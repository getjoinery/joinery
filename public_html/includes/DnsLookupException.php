<?php
/**
 * DnsLookupException - thrown by DnsResolver when a DNS lookup fails at the
 * resolver/transport level (timeout, SERVFAIL, network error) rather than
 * succeeding with an empty result ("no such record").
 *
 * The distinction is deliberate and load-bearing. Callers that must fail-open
 * (email-auth, provisioning, validation) catch this and proceed; callers that
 * must fail-closed (SSRF guards) catch this and block. A genuine "no record"
 * is an empty array and never throws — see DnsResolver.
 *
 * @version 1.0
 */

class DnsLookupException extends Exception {}
