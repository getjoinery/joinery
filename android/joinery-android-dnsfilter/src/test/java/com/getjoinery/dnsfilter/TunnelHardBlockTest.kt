package com.getjoinery.dnsfilter

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Ported 1:1 from the iOS TunnelHardBlockTests — the strict-mode enforcement
 *  core is verified off the service so the SNI parser and subdomain matcher are
 *  exercised against real TLS framing, not the live tunnel. */
class TunnelHardBlockTest {

    // MARK: HardBlockList

    @Test fun exactMatch() {
        val list = HardBlockList(listOf("example.com", "tracker.net"))
        assertTrue(list.blocks("example.com"))
        assertTrue(list.blocks("tracker.net"))
        assertFalse(list.blocks("notblocked.com"))
    }

    @Test fun subdomainMatch() {
        val list = HardBlockList(listOf("example.com"))
        assertTrue(list.blocks("cdn.example.com"))
        assertTrue(list.blocks("a.b.example.com"))
        // A sibling that merely ends in the string but isn't a subdomain.
        assertFalse(list.blocks("notexample.com"))
        assertFalse(list.blocks("example.com.evil.net"))
    }

    @Test fun caseAndTrailingDotInsensitive() {
        val list = HardBlockList(listOf("Example.COM."))
        assertTrue(list.blocks("EXAMPLE.com"))
        assertTrue(list.blocks("cdn.example.com."))
    }

    @Test fun emptyListMatchesNothing() {
        val list = HardBlockList(emptyList())
        assertTrue(list.isEmpty)
        assertFalse(list.blocks("anything.com"))
    }

    // MARK: SNI extraction

    @Test fun extractSNIFromClientHello() {
        val hello = clientHello("blocked.example.com")
        assertEquals("blocked.example.com", TlsClientHello.serverName(hello))
    }

    @Test fun clientHelloWithoutSNIReturnsNull() {
        assertNull(TlsClientHello.serverName(clientHello(null)))
    }

    @Test fun nonHandshakeRecordReturnsNull() {
        // Application-data content type (23), not a handshake.
        assertNull(TlsClientHello.serverName(byteArrayOf(23, 3, 3, 0, 1, 0)))
    }

    @Test fun truncatedRecordDoesNotCrash() {
        val full = clientHello("example.com")
        for (cut in full.indices) {
            // Any prefix must parse to null or the name, never throw.
            TlsClientHello.serverName(full.copyOf(cut))
        }
    }

    @Test fun sniEnforcementEndToEnd() {
        // The whole point of strict mode: a ClientHello naming a blocked host is
        // caught by SNI even though this test never touched DNS.
        val list = HardBlockList(listOf("example.com"))
        val sni = TlsClientHello.serverName(clientHello("cdn.example.com"))
        assertNotNull(sni)
        assertTrue(list.blocks(sni!!))
    }

    // MARK: ClientHello builder

    /** Assemble a minimal but structurally valid TLS 1.2 ClientHello, optionally
     *  carrying an SNI extension. Lengths are computed, so the parser is
     *  exercised against real framing rather than hand-counted constants. */
    private fun clientHello(serverName: String?): ByteArray {
        val extensions = ArrayList<Byte>()
        if (serverName != null) {
            val host = serverName.toByteArray(Charsets.UTF_8)
            val sniEntry = ArrayList<Byte>()
            sniEntry.add(0x00)                       // name type host_name
            sniEntry.addAll(u16(host.size)); sniEntry.addAll(host.toList())
            val extData = ArrayList<Byte>()
            extData.addAll(u16(sniEntry.size)); extData.addAll(sniEntry)   // server_name_list
            extensions.addAll(u16(0x0000))                                  // extension type SNI
            extensions.addAll(u16(extData.size)); extensions.addAll(extData)
        }

        val body = ArrayList<Byte>()
        body.addAll(listOf(0x03.toByte(), 0x03.toByte()))    // client version
        body.addAll(ByteArray(32).toList())                  // random
        body.add(0x00)                                       // session id length
        body.addAll(u16(2)); body.addAll(listOf(0x00.toByte(), 0x2f.toByte())) // cipher suites
        body.addAll(listOf(0x01.toByte(), 0x00.toByte()))    // compression methods
        body.addAll(u16(extensions.size)); body.addAll(extensions)          // extensions block

        val handshake = ArrayList<Byte>()
        handshake.add(0x01)                                  // ClientHello
        handshake.addAll(u24(body.size)); handshake.addAll(body)

        val record = ArrayList<Byte>()
        record.addAll(listOf(0x16.toByte(), 0x03.toByte(), 0x01.toByte()))  // handshake, version
        record.addAll(u16(handshake.size)); record.addAll(handshake)
        return record.toByteArray()
    }

    private fun u16(v: Int) = listOf(((v shr 8) and 0xff).toByte(), (v and 0xff).toByte())
    private fun u24(v: Int) = listOf(((v shr 16) and 0xff).toByte(), ((v shr 8) and 0xff).toByte(), (v and 0xff).toByte())
}
