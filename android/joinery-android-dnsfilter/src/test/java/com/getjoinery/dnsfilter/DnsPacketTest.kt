package com.getjoinery.dnsfilter

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The standard-mode forwarder framing: a query captured off the tun must parse,
 * and the reply we synthesize must be a well-formed IP/UDP packet the OS
 * accepts (correct addresses, ports, lengths, checksums). Both families —
 * dropping IPv6 would silently bypass filtering on a v6 network (guardrail 5).
 */
class DnsPacketTest {

    private val dnsQuery = byteArrayOf(0x12, 0x34, 0x01, 0x00, 0, 1, 0, 0, 0, 0, 0, 0)
    private val dnsAnswer = byteArrayOf(0x12, 0x34, 0x81.toByte(), 0x80.toByte(), 0, 1, 0, 1, 0, 0, 0, 0)

    @Test fun ipv4Roundtrip() {
        val appAddr = byteArrayOf(10, 0, 0, 5)
        val dnsAddr = byteArrayOf(10, 111, 0, 2)
        val packet = DnsUdpPacket.build(4, appAddr, dnsAddr, 40000, 53, dnsQuery)

        val parsed = DnsUdpPacket.parse(packet)!!
        assertEquals(4, parsed.version)
        assertEquals(40000, parsed.srcPort)
        assertEquals(53, parsed.dstPort)
        assertArrayEquals(appAddr, parsed.srcAddr)
        assertArrayEquals(dnsAddr, parsed.dstAddr)
        assertArrayEquals(dnsQuery, parsed.payload)
        assertTrue("IPv4 header checksum must validate", ipv4HeaderChecksumValid(packet))
    }

    @Test fun ipv6Roundtrip() {
        val appAddr = ByteArray(16).also { it[0] = 0xfd.toByte(); it[15] = 9 }
        val dnsAddr = ByteArray(16).also { it[0] = 0xfd.toByte(); it[15] = 2 }
        val packet = DnsUdpPacket.build(6, appAddr, dnsAddr, 51000, 53, dnsQuery)

        val parsed = DnsUdpPacket.parse(packet)!!
        assertEquals(6, parsed.version)
        assertEquals(51000, parsed.srcPort)
        assertEquals(53, parsed.dstPort)
        assertArrayEquals(appAddr, parsed.srcAddr)
        assertArrayEquals(dnsAddr, parsed.dstAddr)
        assertArrayEquals(dnsQuery, parsed.payload)
    }

    @Test fun buildResponseSwapsEndpoints() {
        val appAddr = byteArrayOf(10, 0, 0, 5)
        val dnsAddr = byteArrayOf(10, 111, 0, 2)
        val query = DnsUdpPacket.parse(DnsUdpPacket.build(4, appAddr, dnsAddr, 40000, 53, dnsQuery))!!

        val reply = DnsUdpPacket.parse(DnsUdpPacket.buildResponse(query, dnsAnswer))!!
        // The reply flows resolver -> app: endpoints swapped.
        assertArrayEquals(dnsAddr, reply.srcAddr)
        assertArrayEquals(appAddr, reply.dstAddr)
        assertEquals(53, reply.srcPort)
        assertEquals(40000, reply.dstPort)
        assertArrayEquals(dnsAnswer, reply.payload)
    }

    @Test fun tcpAndTruncatedReturnNull() {
        // A TCP packet (protocol 6) is not a DNS/UDP query.
        val tcp = ByteArray(28).also { it[0] = 0x45.toByte(); it[9] = 6 }
        assertNull(DnsUdpPacket.parse(tcp))
        // Truncated header.
        assertNull(DnsUdpPacket.parse(byteArrayOf(0x45, 0, 0, 10)))
        assertNull(DnsUdpPacket.parse(ByteArray(0)))
    }

    /** Ones-complement sum over the 20-byte IPv4 header must fold to zero. */
    private fun ipv4HeaderChecksumValid(packet: ByteArray): Boolean {
        var sum = 0L
        var i = 0
        while (i < 20) {
            sum += (packet[i].toInt() and 0xFF shl 8) or (packet[i + 1].toInt() and 0xFF)
            i += 2
        }
        while (sum shr 16 != 0L) sum = (sum and 0xFFFF) + (sum shr 16)
        return sum.toInt() == 0xFFFF
    }
}
