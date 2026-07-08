package com.getjoinery.dnsfilter

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.VpnService
import android.os.Build
import android.os.ParcelFileDescriptor
import java.io.FileInputStream
import java.io.FileOutputStream
import java.util.concurrent.Executors
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread

/**
 * The DNS-filtering tunnel. Standard mode claims only DNS traffic: the service
 * advertises a virtual DNS address, routes just that address into the tun, and
 * forwards every captured query to the device's DoH resolver (which applies
 * this device's server-side policy). Everything else on the network is
 * untouched. Both IPv4 and IPv6 DNS are captured, so filtering can't be
 * bypassed on a v6 network (guardrail 5).
 *
 * The forwarder moves real packets — a non-blocked hostname resolves through
 * the tunnel and a blocked one does not, which is what the Phase 2 gate proves
 * (guardrail 2). Strict-mode connection-level enforcement (routing all traffic
 * and dropping by SNI via [HardBlockList]/[TlsClientHello]) is the deferred
 * Phase 4 datapath; the engine is present and unit-tested but not wired here,
 * and the app never switches this service into strict mode until it is
 * (DnsFilterConfig.strictModeAvailable, guardrail 3).
 */
class DnsFilterVpnService : VpnService() {

    @Volatile private var running = false
    private var tunnel: ParcelFileDescriptor? = null
    private var pumpThread: Thread? = null
    private var workers: ThreadPoolExecutor? = null
    private val writeLock = Any()
    private var doh: DohClient? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                teardown()
                stopForegroundCompat()
                stopSelf()
                DnsFilterState.setStatus(ProtectionStatus.OFF)
                return START_NOT_STICKY
            }
            else -> {
                val config = readConfig(intent) ?: DnsFilterState.savedConfig(applicationContext)
                if (config == null || config.dohUrl.isEmpty()) {
                    stopSelf()
                    DnsFilterState.setStatus(ProtectionStatus.OFF)
                    return START_NOT_STICKY
                }
                startForegroundCompat(config.brandName)
                establish(config)
                // START_STICKY: the OEM/system may kill and restart us; on
                // restart the persisted config re-establishes the tunnel.
                return START_STICKY
            }
        }
    }

    private fun readConfig(intent: Intent?): DnsFilterState.Config? {
        val doh = intent?.getStringExtra(EXTRA_DOH_URL) ?: return null
        val modeSlug = intent.getStringExtra(EXTRA_MODE) ?: "standard"
        return DnsFilterState.Config(
            dohUrl = doh,
            mode = if (modeSlug == "strict") ProtectionMode.STRICT else ProtectionMode.STANDARD,
            brandName = intent.getStringExtra(EXTRA_BRAND) ?: "",
            hardBlockHostnames = intent.getStringArrayExtra(EXTRA_HARDBLOCKS)?.toList() ?: emptyList(),
        )
    }

    private fun establish(config: DnsFilterState.Config) {
        teardown() // reconfigure in place: replace any prior tunnel cleanly
        val builder = Builder()
            .setSession(config.brandName.ifEmpty { "DNS Filter" })
            .setMtu(MTU)
            // Tunnel interface addresses (link-local scoped, both families).
            .addAddress(TUN_ADDR_V4, 32)
            .addAddress(TUN_ADDR_V6, 128)
            // Advertise the virtual resolver and route only it into the tun —
            // "claims only DNS traffic". Both families so v6 DNS is captured too.
            .addDnsServer(DNS_ADDR_V4)
            .addDnsServer(DNS_ADDR_V6)
            .addRoute(DNS_ADDR_V4, 32)
            .addRoute(DNS_ADDR_V6, 128)

        // Don't route our own app's traffic through the tunnel — the DoH
        // connection to the resolver must egress normally, never loop back in.
        try {
            builder.addDisallowedApplication(packageName)
        } catch (e: Exception) {
            // Package always resolves to itself; ignore on the off chance.
        }

        val fd = try {
            builder.establish()
        } catch (e: Exception) {
            null
        }
        if (fd == null) {
            stopSelf()
            DnsFilterState.setStatus(ProtectionStatus.OFF)
            return
        }
        tunnel = fd
        doh = DohClient(config.dohUrl)
        running = true
        workers = Executors.newFixedThreadPool(WORKER_THREADS) as ThreadPoolExecutor
        pumpThread = thread(name = "dns-pump", start = true) { pump(fd) }
        DnsFilterState.setStatus(ProtectionStatus.ON)
    }

    /** Read packets off the tun, forward DNS queries, write answers back. */
    private fun pump(fd: ParcelFileDescriptor) {
        val input = FileInputStream(fd.fileDescriptor)
        val output = FileOutputStream(fd.fileDescriptor)
        val buffer = ByteArray(MTU)
        try {
            while (running) {
                val n = input.read(buffer)
                if (n < 0) break // EOF: tun closed out from under us — exit, no busy-spin
                if (n == 0) continue
                val packet = buffer.copyOf(n)
                val query = DnsUdpPacket.parse(packet)
                // Only DNS is routed in, but re-check the port defensively.
                if (query != null && query.dstPort == DNS_PORT && query.payload.isNotEmpty()) {
                    workers?.execute { resolveAndReply(query, output) }
                }
            }
        } catch (e: Exception) {
            // A closed tun (teardown) surfaces here as an IOException — expected.
        }
    }

    private fun resolveAndReply(query: DnsUdpPacket, output: FileOutputStream) {
        val answer = doh?.resolve(query.payload) ?: return
        val reply = DnsUdpPacket.buildResponse(query, answer)
        try {
            synchronized(writeLock) {
                output.write(reply)
                output.flush()
            }
        } catch (e: Exception) {
            // Tunnel closed mid-write; drop.
        }
    }

    private fun teardown() {
        running = false
        pumpThread?.interrupt()
        pumpThread = null
        workers?.shutdownNow()
        workers?.awaitTermination(1, TimeUnit.SECONDS)
        workers = null
        try {
            tunnel?.close()
        } catch (e: Exception) {
        }
        tunnel = null
        doh = null
    }

    override fun onDestroy() {
        teardown()
        // A system-initiated kill (not an explicit stop) leaves the persisted
        // "running" flag set, so START_STICKY / the boot receiver restore it.
        if (DnsFilterState.status.value != ProtectionStatus.OFF) {
            DnsFilterState.setStatus(ProtectionStatus.OFF)
        }
        super.onDestroy()
    }

    override fun onRevoke() {
        // Another VPN took the slot, or the user revoked consent. Stop cleanly.
        DnsFilterState.setRunning(applicationContext, false)
        teardown()
        stopForegroundCompat()
        stopSelf()
        DnsFilterState.setStatus(ProtectionStatus.OFF)
    }

    // MARK: Foreground notification

    private fun startForegroundCompat(brandName: String) {
        val manager = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "Protection status",
                NotificationManager.IMPORTANCE_LOW,
            ).apply { setShowBadge(false) }
            manager.createNotificationChannel(channel)
        }
        val launch = packageManager.getLaunchIntentForPackage(packageName)
        val pending = launch?.let {
            PendingIntent.getActivity(
                this, 0, it,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
        }
        val notification: Notification = androidx.core.app.NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(brandName.ifEmpty { "Protection on" })
            .setContentText("Filtering is on for this device.")
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setOngoing(true)
            .setPriority(androidx.core.app.NotificationCompat.PRIORITY_LOW)
            .apply { pending?.let { setContentIntent(it) } }
            .build()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIF_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SYSTEM_EXEMPTED)
        } else {
            startForeground(NOTIF_ID, notification)
        }
    }

    private fun stopForegroundCompat() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            stopForeground(STOP_FOREGROUND_REMOVE)
        } else {
            @Suppress("DEPRECATION")
            stopForeground(true)
        }
    }

    companion object {
        const val ACTION_START = "com.getjoinery.dnsfilter.START"
        const val ACTION_STOP = "com.getjoinery.dnsfilter.STOP"
        const val EXTRA_DOH_URL = "doh_url"
        const val EXTRA_MODE = "mode"
        const val EXTRA_BRAND = "brand"
        const val EXTRA_HARDBLOCKS = "hard_block_hostnames"

        private const val CHANNEL_ID = "scrolldaddy_vpn"
        private const val NOTIF_ID = 0x5DD
        private const val MTU = 1500
        private const val DNS_PORT = 53
        private const val WORKER_THREADS = 8

        // Link-local-scoped virtual interface + resolver addresses. The resolver
        // address is the only thing routed into the tun.
        private const val TUN_ADDR_V4 = "10.111.0.1"
        private const val TUN_ADDR_V6 = "fd00:0:0:0:0:0:0:1"
        private const val DNS_ADDR_V4 = "10.111.0.2"
        private const val DNS_ADDR_V6 = "fd00:0:0:0:0:0:0:2"
    }
}
