package com.getjoinery.dnsfilter

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Guardrail 7: every status the UI renders must be reachable from a real
 * transition — no dead enum values. The tunnel lifecycle drives OFF →
 * CONNECTING (start requested) → ON (tunnel up) → OFF (stopped/revoked), and
 * the status flow emits each one.
 */
class DnsFilterStateTest {

    @Test fun statusFlowReachesEveryValue() {
        val seen = mutableListOf<ProtectionStatus>()
        seen.add(DnsFilterState.status.value)

        DnsFilterState.setStatus(ProtectionStatus.CONNECTING)
        seen.add(DnsFilterState.status.value)

        DnsFilterState.setStatus(ProtectionStatus.ON)
        seen.add(DnsFilterState.status.value)

        DnsFilterState.setStatus(ProtectionStatus.OFF)
        seen.add(DnsFilterState.status.value)

        // Every enum value was actually produced by the state holder.
        assertEquals(
            ProtectionStatus.values().toSet(),
            seen.toSet(),
        )
    }
}
