package com.getjoinery.dnsfilter

/**
 * IP/UDP framing for the standard-mode DNS forwarder. The VpnService reads raw
 * IP packets off the tun interface; this parses the ones carrying a DNS query
 * (UDP), hands the DNS payload to the DoH client, and reframes the resolver's
 * answer as a UDP reply the OS delivers back to the querying app.
 *
 * Kept out of the service so it is pure and unit-testable (build → parse
 * roundtrip, checksum correctness). Both address families are handled — on an
 * IPv6 network the DNS query arrives as an IPv6/UDP packet, and dropping it
 * would silently bypass filtering (guardrail 5).
 */
data class DnsUdpPacket(
    /** 4 or 6. */
    val version: Int,
    val srcAddr: ByteArray,
    val dstAddr: ByteArray,
    val srcPort: Int,
    val dstPort: Int,
    /** The UDP payload — the DNS message. */
    val payload: ByteArray,
) {
    companion object {
        const val PROTO_UDP = 17

        /** Parse an IP packet, returning the UDP view only for UDP packets with
         *  no IPv6 extension headers; null for anything else (TCP, ICMP,
         *  truncated, options we don't expect on a DNS query). */
        fun parse(packet: ByteArray): DnsUdpPacket? {
            if (packet.isEmpty()) return null
            return when (packet[0].toInt() and 0xF0 shr 4) {
                4 -> parseV4(packet)
                6 -> parseV6(packet)
                else -> null
            }
        }

        private fun parseV4(p: ByteArray): DnsUdpPacket? {
            if (p.size < 20) return null
            val ihl = (p[0].toInt() and 0x0F) * 4
            if (ihl < 20 || p.size < ihl + 8) return null
            if ((p[9].toInt() and 0xFF) != PROTO_UDP) return null
            val src = p.copyOfRange(12, 16)
            val dst = p.copyOfRange(16, 20)
            val srcPort = u16(p, ihl)
            val dstPort = u16(p, ihl + 2)
            val udpLen = u16(p, ihl + 4)
            val payloadLen = (udpLen - 8).coerceAtLeast(0)
            val payloadStart = ihl + 8
            val payloadEnd = (payloadStart + payloadLen).coerceAtMost(p.size)
            val payload = p.copyOfRange(payloadStart, payloadEnd)
            return DnsUdpPacket(4, src, dst, srcPort, dstPort, payload)
        }

        private fun parseV6(p: ByteArray): DnsUdpPacket? {
            if (p.size < 48) return null
            // next header at offset 6; only a bare UDP header is handled.
            if ((p[6].toInt() and 0xFF) != PROTO_UDP) return null
            val src = p.copyOfRange(8, 24)
            val dst = p.copyOfRange(24, 40)
            val srcPort = u16(p, 40)
            val dstPort = u16(p, 42)
            val udpLen = u16(p, 44)
            val payloadLen = (udpLen - 8).coerceAtLeast(0)
            val payloadStart = 48
            val payloadEnd = (payloadStart + payloadLen).coerceAtMost(p.size)
            val payload = p.copyOfRange(payloadStart, payloadEnd)
            return DnsUdpPacket(6, src, dst, srcPort, dstPort, payload)
        }

        /** Build the UDP reply to [request] carrying [responsePayload] (the DNS
         *  answer): source/destination swapped so it flows back to the app. */
        fun buildResponse(request: DnsUdpPacket, responsePayload: ByteArray): ByteArray =
            build(
                version = request.version,
                srcAddr = request.dstAddr,
                dstAddr = request.srcAddr,
                srcPort = request.dstPort,
                dstPort = request.srcPort,
                payload = responsePayload,
            )

        /** Assemble a complete IP+UDP packet with correct checksums. */
        fun build(
            version: Int,
            srcAddr: ByteArray,
            dstAddr: ByteArray,
            srcPort: Int,
            dstPort: Int,
            payload: ByteArray,
        ): ByteArray {
            val udpLen = 8 + payload.size
            val udp = ByteArray(udpLen)
            put16(udp, 0, srcPort)
            put16(udp, 2, dstPort)
            put16(udp, 4, udpLen)
            // checksum (offset 6) filled after the pseudo-header is known.
            payload.copyInto(udp, 8)

            return if (version == 4) buildV4(srcAddr, dstAddr, udp) else buildV6(srcAddr, dstAddr, udp)
        }

        private fun buildV4(src: ByteArray, dst: ByteArray, udp: ByteArray): ByteArray {
            val total = 20 + udp.size
            val out = ByteArray(total)
            out[0] = 0x45.toByte()          // version 4, IHL 5
            out[1] = 0                       // DSCP/ECN
            put16(out, 2, total)             // total length
            put16(out, 4, 0)                 // identification
            put16(out, 6, 0x4000)            // flags: Don't Fragment
            out[8] = 64                      // TTL
            out[9] = PROTO_UDP.toByte()      // protocol
            put16(out, 10, 0)                // header checksum placeholder
            src.copyInto(out, 12)
            dst.copyInto(out, 16)
            put16(out, 10, checksum(out, 0, 20))

            // UDP checksum over the IPv4 pseudo-header + UDP.
            val udpCsum = udpChecksumV4(src, dst, udp)
            put16(udp, 6, udpCsum)
            udp.copyInto(out, 20)
            return out
        }

        private fun buildV6(src: ByteArray, dst: ByteArray, udp: ByteArray): ByteArray {
            val total = 40 + udp.size
            val out = ByteArray(total)
            out[0] = 0x60.toByte()           // version 6
            put16(out, 4, udp.size)          // payload length
            out[6] = PROTO_UDP.toByte()      // next header
            out[7] = 64                      // hop limit
            src.copyInto(out, 8)
            dst.copyInto(out, 24)

            // IPv6 requires a non-zero UDP checksum.
            val udpCsum = udpChecksumV6(src, dst, udp)
            put16(udp, 6, if (udpCsum == 0) 0xFFFF else udpCsum)
            udp.copyInto(out, 40)
            return out
        }

        // MARK: checksums

        private fun udpChecksumV4(src: ByteArray, dst: ByteArray, udp: ByteArray): Int {
            var sum = 0L
            sum += be16(src, 0) + be16(src, 2)
            sum += be16(dst, 0) + be16(dst, 2)
            sum += PROTO_UDP
            sum += udp.size
            sum += sumBytes(udp, 0, udp.size)
            return fold(sum)
        }

        private fun udpChecksumV6(src: ByteArray, dst: ByteArray, udp: ByteArray): Int {
            var sum = 0L
            var i = 0
            while (i < 16) { sum += be16(src, i); i += 2 }
            i = 0
            while (i < 16) { sum += be16(dst, i); i += 2 }
            sum += udp.size          // upper-layer packet length (fits 16 bits for DNS)
            sum += PROTO_UDP         // next header
            sum += sumBytes(udp, 0, udp.size)
            return fold(sum)
        }

        private fun checksum(buf: ByteArray, start: Int, len: Int): Int =
            fold(sumBytes(buf, start, len))

        private fun sumBytes(buf: ByteArray, start: Int, len: Int): Long {
            var sum = 0L
            var i = start
            val end = start + len
            while (i + 1 < end) {
                sum += be16(buf, i)
                i += 2
            }
            if (i < end) sum += (buf[i].toInt() and 0xFF) shl 8   // odd trailing byte
            return sum
        }

        private fun fold(value: Long): Int {
            var sum = value
            while (sum shr 16 != 0L) sum = (sum and 0xFFFF) + (sum shr 16)
            return (sum.inv() and 0xFFFF).toInt()
        }

        private fun be16(buf: ByteArray, off: Int): Int =
            (buf[off].toInt() and 0xFF shl 8) or (buf[off + 1].toInt() and 0xFF)

        private fun u16(buf: ByteArray, off: Int): Int = be16(buf, off)

        private fun put16(buf: ByteArray, off: Int, value: Int) {
            buf[off] = (value ushr 8 and 0xFF).toByte()
            buf[off + 1] = (value and 0xFF).toByte()
        }
    }

    // Data class with ByteArray members needs explicit equals/hashCode for the
    // roundtrip tests to compare by content.
    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (other !is DnsUdpPacket) return false
        return version == other.version &&
            srcAddr.contentEquals(other.srcAddr) &&
            dstAddr.contentEquals(other.dstAddr) &&
            srcPort == other.srcPort &&
            dstPort == other.dstPort &&
            payload.contentEquals(other.payload)
    }

    override fun hashCode(): Int {
        var result = version
        result = 31 * result + srcAddr.contentHashCode()
        result = 31 * result + dstAddr.contentHashCode()
        result = 31 * result + srcPort
        result = 31 * result + dstPort
        result = 31 * result + payload.contentHashCode()
        return result
    }
}
