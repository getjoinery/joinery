package com.getjoinery.mail

import com.getjoinery.android.vault.VaultCrypto

/**
 * The `device_hits` wire form (thread_list): the message ids this phone's
 * own index found, ascending, as unsigned LEB128 differences, base64 — the
 * browser's shape, pinned by plugins/mailbox/tests/fixtures/device_hits_vector.json.
 */
object DeviceHits {
    fun pack(ids: List<Long>): String {
        val out = java.io.ByteArrayOutputStream()
        var prev = 0L
        for (id in ids.filter { it > 0 }.distinct().sorted()) {
            var d = id - prev
            prev = id
            while (true) {
                val b = (d and 0x7F).toInt()
                d = d ushr 7
                if (d == 0L) { out.write(b); break }
                out.write(b or 0x80)
            }
        }
        return VaultCrypto.b64encode(out.toByteArray())
    }

    fun unpack(packed: String): List<Long> {
        val bytes = VaultCrypto.b64decode(packed)
        val out = ArrayList<Long>()
        var i = 0
        var prev = 0L
        while (i < bytes.size) {
            var v = 0L
            var shift = 0
            while (true) {
                val b = bytes[i++].toInt() and 0xFF
                v = v or ((b and 0x7F).toLong() shl shift)
                if (b and 0x80 == 0) break
                shift += 7
            }
            prev += v
            out.add(prev)
        }
        return out
    }
}
