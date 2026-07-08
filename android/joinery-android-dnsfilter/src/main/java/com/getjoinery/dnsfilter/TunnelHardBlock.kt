package com.getjoinery.dnsfilter

/**
 * The strict-mode enforcement core, kept out of the VpnService so it is plain,
 * deterministic, and unit-testable. Two jobs:
 *
 * 1. **Hostname matching** ([HardBlockList]) — the synced list of always-on,
 *    block-action, hard-block hostnames. A block matches the host exactly or
 *    any subdomain of it, so blocking `example.com` also stops
 *    `cdn.example.com`.
 * 2. **SNI extraction** ([TlsClientHello]) — pull the destination hostname out
 *    of a TLS ClientHello. This is what makes strict mode "not just DNS": a
 *    connection to a blocked host is dropped even when the app resolved the IP
 *    through its own hardcoded DoH, because the SNI still names the host.
 *
 * The VpnService owns the socket plumbing; it calls into these two types for
 * every decision. Ported 1:1 from the iOS TunnelHardBlock.swift, with the same
 * test suite.
 */
class HardBlockList(hostnames: List<String>) {
    /** Lowercased, dot-trimmed hostnames. */
    val hosts: Set<String> = hostnames.map { normalize(it) }.filter { it.isNotEmpty() }.toSet()

    val isEmpty: Boolean get() = hosts.isEmpty()

    /** Exact match, or [host] is a subdomain of a blocked name. Case- and
     *  trailing-dot-insensitive. */
    fun blocks(host: String): Boolean {
        val name = normalize(host)
        if (name.isEmpty()) return false
        if (hosts.contains(name)) return true
        // Walk parent domains: a.b.example.com -> b.example.com -> example.com.
        var idx = 0
        while (true) {
            val dot = name.indexOf('.', idx)
            if (dot < 0) break
            val parent = name.substring(dot + 1)
            if (hosts.contains(parent)) return true
            idx = dot + 1
        }
        return false
    }

    companion object {
        fun normalize(host: String): String {
            var h = host.lowercase()
            while (h.endsWith(".")) h = h.dropLast(1)
            while (h.startsWith(".")) h = h.drop(1)
            return h
        }
    }
}

/**
 * Minimal TLS ClientHello reader — just enough to recover the SNI host name.
 * Operates on the bytes at the start of a TLS record (handshake content type).
 */
object TlsClientHello {
    /** Return the SNI host_name from a ClientHello, or null if the bytes are not
     *  a ClientHello or carry no SNI extension. Bounds-checked throughout: a
     *  truncated or malformed record yields null, never a crash. */
    fun serverName(bytes: ByteArray): String? {
        val r = Reader(bytes)

        // TLS record header: type(1) version(2) length(2).
        val contentType = r.u8() ?: return null
        if (contentType != 22) return null                          // handshake
        if (!r.skip(2)) return null                                 // record version
        val recordLen = r.u16() ?: return null
        // The handshake message must fit inside the declared record.
        val handshakeEnd = minOf(r.offset + recordLen, bytes.size)

        // Handshake header: type(1) length(3).
        val hsType = r.u8() ?: return null
        if (hsType != 1) return null                                // ClientHello
        if (!r.skip(3)) return null                                 // handshake length

        // ClientHello body.
        if (!r.skip(2)) return null                                 // client version
        if (!r.skip(32)) return null                                // random
        val sidLen = r.u8() ?: return null
        if (!r.skip(sidLen)) return null                            // session id
        val csLen = r.u16() ?: return null
        if (!r.skip(csLen)) return null                            // cipher suites
        val compLen = r.u8() ?: return null
        if (!r.skip(compLen)) return null                          // compression
        val extTotal = r.u16() ?: return null                      // extensions block
        val extEnd = minOf(r.offset + extTotal, handshakeEnd)

        // Walk extensions looking for server_name (0x0000).
        while (r.offset + 4 <= extEnd) {
            val extType = r.u16() ?: return null
            val extLen = r.u16() ?: return null
            val extDataEnd = r.offset + extLen
            if (extDataEnd > extEnd) return null
            if (extType == 0x0000) {
                return parseServerNameList(r, extDataEnd)
            }
            r.offset = extDataEnd
        }
        return null
    }

    private fun parseServerNameList(r: Reader, end: Int): String? {
        // ServerNameList: list_length(2), then entries of type(1) length(2) name.
        r.u16() ?: return null // list length (bounded by end)
        while (r.offset + 3 <= end) {
            val nameType = r.u8() ?: return null
            val nameLen = r.u16() ?: return null
            val nameEnd = r.offset + nameLen
            if (nameEnd > end) return null
            if (nameType == 0) { // host_name
                val slice = r.bytes.copyOfRange(r.offset, nameEnd)
                return String(slice, Charsets.UTF_8)
            }
            r.offset = nameEnd
        }
        return null
    }

    /** A bounds-safe cursor over the record bytes. Bytes are read as unsigned. */
    private class Reader(val bytes: ByteArray) {
        var offset = 0

        fun u8(): Int? {
            if (offset >= bytes.size) return null
            val v = bytes[offset].toInt() and 0xFF
            offset += 1
            return v
        }

        fun u16(): Int? {
            if (offset + 2 > bytes.size) return null
            val v = (bytes[offset].toInt() and 0xFF shl 8) or (bytes[offset + 1].toInt() and 0xFF)
            offset += 2
            return v
        }

        fun skip(n: Int): Boolean {
            if (n < 0 || offset + n > bytes.size) return false
            offset += n
            return true
        }
    }
}
