package com.getjoinery.mail.fortress

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.getjoinery.android.ApiClient
import com.getjoinery.android.JsonValue
import com.getjoinery.android.MultipartFile
import com.getjoinery.android.vault.VaultCrypto
import com.getjoinery.mail.MailboxStore
import com.getjoinery.mail.ai.AiJudge
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * The work a phone holding the mail key does while the mailbox is open — and
 * never in the background (an OS-scheduled run is not the person present):
 *
 *  1. **The relay drain** (§ R7): relay-sealed arrivals are opened, parsed
 *     ([MimeParser]), checked against the mailbox's mail rules ([FilterMatch],
 *     § R14), sealed under their own row key and stored.
 *  2. **The relay pin** (§ R7): the relay's signed statement of the key it
 *     seals to is checked against the relay pinned for each mailbox; a
 *     mismatch raises [alarm]. Trusting a new relay is a computer's job.
 *  3. **AI on the person's own model** (§ R13): each device-capable recipe's
 *     queue is judged ([AiJudge]) — Wi-Fi only unless the person says so.
 *  4. **Rules on existing mail** (§ R14): "apply to existing" on end-to-end
 *     mail, evaluated here, reported as matched ids.
 *
 * One pass at a time per process; the held-key lock stops a pass in flight.
 */
class FortressWork private constructor(
    private val context: Context,
    private val client: ApiClient,
    private val fortress: FortressMail,
) {
    /** The progress line under the banner, or null. */
    var status by mutableStateOf<String?>(null)
        private set

    /** A relay mismatch to show, or null. */
    var alarm by mutableStateOf<RelayAlarm?>(null)

    data class RelayAlarm(val address: String, val reason: String, val expected: String?, val reported: String?)

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var running: Job? = null
    private var pinsCheckedEpoch = -1
    private var aiStoppedEpoch = -1
    private var aiJudged = 0
    /** Rows this phone could not open for AI (sealed to a key it does not
     *  hold): not asked again in this process. */
    private val aiUnopenable = HashSet<Int>()

    val aiSettings = DeviceAiSettings(context, fortress)

    private suspend fun post(action: String, body: JsonValue): JsonValue? =
        client.submitAction(action, body)["data"]

    private var lastRun = 0L

    /** One pass of the work. Refreshes ask often; a pass runs at most every
     *  [MIN_GAP_MS] unless [force]d (the key just opened). */
    fun runForeground(store: MailboxStore, force: Boolean = false, onChanged: () -> Unit) {
        if (running?.isActive == true || !fortress.isOpen) return
        val now = System.currentTimeMillis()
        if (!force && now - lastRun < MIN_GAP_MS) return
        lastRun = now
        running = scope.launch {
            try {
                val stored = drainPending()
                if (stored > 0) onChanged()
                checkRelayPins(store)
                val judged = drainAi(store)
                if (judged > 0) onChanged()
                val applied = ruleBacklog()
                if (applied > 0) onChanged()
            } catch (t: Throwable) {
                // Nothing a pass meets may take the app down with it (R10).
                status = null
            }
        }
    }

    // MARK: 1. Relay-sealed arrivals

    private val rulesByAlias = HashMap<Int, List<JsonValue>>()

    private suspend fun rulesFor(aliasId: Int?): List<JsonValue> {
        if (aliasId == null) return emptyList()
        rulesByAlias[aliasId]?.let { return it }
        val rules = try {
            post("mailbox/device_rules", JsonValue.obj("alias_id" to JsonValue.Num(aliasId.toDouble())))?.get("rules")?.arrayValue ?: emptyList()
        } catch (e: Exception) {
            emptyList()
        }
        rulesByAlias[aliasId] = rules
        return rules
    }

    val relayGuard = RelayDrainGuard(
        com.getjoinery.android.vault.PrefsKeyValueStore(context.getSharedPreferences("joinery.relaydrain", Context.MODE_PRIVATE)),
        Runtime.getRuntime().maxMemory(),
    )

    /** Parse and store every relay-sealed message waiting for this key. */
    suspend fun drainPending(): Int {
        val epoch0 = fortress.lockEpoch
        var stored = 0
        val refetched = HashSet<Int>()
        rulesByAlias.clear()
        relayGuard.startDrain(fortress.keyGeneration)
        var memoryTrouble = false
        var finished = false
        var largestParsed = 0L
        try {
            while (fortress.isOpen && fortress.lockEpoch == epoch0) {
                // The fetch holds the whole sealed message too: anything that
                // goes wrong here, memory included, is caught (R10).
                val d = try {
                    client.submitAction(
                        "mailbox/fortress_pending",
                        JsonValue.obj(
                            "skip" to JsonValue.Str(relayGuard.skipParam()),
                            "max_bytes" to JsonValue.Num(relayGuard.requestCap.toDouble()),
                        ),
                        maxResponseBytes = relayGuard.maxResponseBytes,
                    )["data"]
                } catch (t: com.getjoinery.android.JoineryApiError.TooLarge) {
                    // Larger than estimated, not necessarily unhealthy: ask
                    // smaller for the rest of this drain (X5).
                    if (relayGuard.askSmaller()) continue
                    status = "A new end-to-end message is too large to open on this phone. Open it on a computer."
                    return stored
                } catch (t: OutOfMemoryError) {
                    // Its id never arrived: ask for smaller ones from now on.
                    memoryTrouble = true
                    relayGuard.shrink()
                    return stored
                } catch (t: Throwable) {
                    return stored
                }
                val item = d?.get("item")?.takeUnless { it.isNull }
                status = RelayDrainGuard.statusLine(
                    remaining = d?.get("remaining")?.intValue ?: 0,
                    tooLarge = d?.get("too_large")?.intValue ?: 0,
                    handedOut = item != null,
                )
                if (item == null) { finished = true; break }
                val id = item["id"]?.intValue ?: break
                val sealedDek = item["sealed_dek"]?.stringValue ?: ""
                if (relayGuard.isSkipped(id, sealedDek)) continue
                val sealedChars = (item["sealed_raw"]?.stringValue?.length ?: 0).toLong()
                if (relayGuard.tooLarge((item["size_bytes"]?.intValue ?: 0).toLong(), sealedChars)) {
                    relayGuard.skip(id, sealedDek)
                    continue
                }
                relayGuard.begin(id, sealedDek)
                try {
                    when (parsePending(item, epoch0)) {
                        "dropped" -> return stored
                        "stale" -> {
                            if (id in refetched) relayGuard.skip(id, sealedDek)
                            refetched.add(id)
                            continue
                        }
                        "stored" -> {
                            stored++
                            largestParsed = maxOf(largestParsed, (item["size_bytes"]?.intValue ?: 0).toLong())
                        }
                        else -> {}
                    }
                } catch (t: Throwable) {
                    when (RelayDrainGuard.classify(t)) {
                        RelayDrainGuard.Failure.RETRY_LATER -> return stored
                        RelayDrainGuard.Failure.SKIP -> relayGuard.skip(id, sealedDek)
                        RelayDrainGuard.Failure.SKIP_AND_SHRINK -> {
                            memoryTrouble = true
                            relayGuard.skip(id, sealedDek)
                            relayGuard.shrink()
                        }
                    }
                } finally {
                    relayGuard.end()
                }
            }
        } finally {
            if (status?.contains("being opened") == true) status = null
        }
        if (finished && !memoryTrouble) relayGuard.cleanDrain(stored, largestParsed)
        return stored
    }

    private suspend fun parsePending(item: JsonValue, epoch0: Int): String {
        val id = item["id"]!!.intValue!!
        val sealedDek = item["sealed_dek"]?.stringValue ?: ""
        val prefix = (item["sealed_ad_prefix"]?.stringValue ?: "mail:") + id
        val key = fortress.opener.dekFor(id, sealedDek)
        if (fortress.lockEpoch != epoch0 || !fortress.isOpen) { key.fill(0); return "dropped" }
        val raw = VaultCrypto.decrypt((item["sealed_raw"]?.stringValue ?: "").removePrefix(FortressOpener.EDGE_FIELD), key, item["raw_ad"]?.stringValue ?: "")
        try {
            val p = MimeParser.parse(raw)
            val s = RelayParse.store(p)
            val fields = s.fields.map { (col, v) ->
                col to JsonValue.Str(if (v.isEmpty()) "" else FortressOpener.sealField(v, key, "$prefix:$col"))
            }
            // Every part in ONE upload, each at its offset (B37).
            val bundle = java.io.ByteArrayOutputStream()
            val parts = ArrayList<JsonValue>()
            for (a in s.parts) {
                val sealed = FortressOpener.sealPart(a.bytes, key, "$prefix:att:${a.mimePart}").toByteArray(Charsets.US_ASCII)
                parts.add(JsonValue.obj(
                    "mime_part" to JsonValue.Str(a.mimePart),
                    "size" to JsonValue.Num(a.bytes.size.toDouble()),
                    "inline" to JsonValue.Bool(a.inline),
                    "offset" to JsonValue.Num(bundle.size().toDouble()),
                    "length" to JsonValue.Num(sealed.size.toDouble()),
                ))
                bundle.write(sealed)
            }
            // The mailbox's rules, evaluated on the plaintext only this phone holds.
            val rules = rulesFor(item["alias_id"]?.takeUnless { it.isNull }?.intValue)
            if (fortress.lockEpoch != epoch0 || !fortress.isOpen) return "dropped"
            val message = FilterMatch.message(
                p.from, item["recipient"]?.stringValue ?: "", p.subject, p.textPlain, p.textHtml,
                (item["size_bytes"]?.intValue ?: raw.size).toLong(), p.attachments.isNotEmpty(),
            )
            val matched = rules.filter { FilterMatch.matches(it, message) }
            val forwards = matched.any { it["forwards"]?.boolValue == true }

            val textFields = ArrayList<Pair<String, String>>()
            textFields.add("id" to id.toString())
            textFields.add("sealed_dek" to sealedDek)
            textFields.add("fields" to JsonValue.Obj(fields).encoded())
            textFields.add("parts" to JsonValue.Arr(parts).encoded())
            textFields.add("spam_headers" to s.spamHeaders.encoded())
            textFields.add("rule_matches" to JsonValue.Arr(matched.map { JsonValue.Num((it["id"]?.doubleValue ?: 0.0)) }).encoded())
            val files = ArrayList<MultipartFile>()
            if (bundle.size() > 0) files.add(MultipartFile("bundle", "parts", "application/octet-stream", bundle.toByteArray()))
            if (forwards) files.add(MultipartFile("forward_raw", "message.eml", "message/rfc822", raw))
            if (fortress.lockEpoch != epoch0 || !fortress.isOpen) return "dropped"
            val answer = client.submitMultipart("mailbox/fortress_parse_store", textFields, files)["data"]
            return when {
                answer?.get("stored")?.boolValue == true -> "stored"
                answer?.get("stale")?.boolValue == true -> "stale"
                else -> "already"
            }
        } finally {
            raw.fill(0)
            key.fill(0)
        }
    }

    // MARK: 2. The relay pin

    suspend fun checkRelayPins(store: MailboxStore) {
        val epoch = fortress.lockEpoch
        if (pinsCheckedEpoch == epoch || !fortress.isOpen) return
        pinsCheckedEpoch = epoch
        val probe = try { com.getjoinery.android.vault.DeviceEnrollment.probe(client, FortressOpener.SCOPE) } catch (e: Exception) { null }
        // Mid-rotation the pending key's secret is not on this phone; the check
        // waits for the rotation to finish, as a browser without the root does.
        if (probe?.pendingPublicKey != null) return
        val myKey = fortress.keys.scopePublicKey(FortressOpener.SCOPE) ?: return
        val boxes = store.home?.mailboxes?.filter { it.isFortress } ?: return
        for (box in boxes) {
            val answer = try {
                post("mailbox/relay_seal_target", JsonValue.obj("alias_id" to JsonValue.Num(box.aliasId.toDouble())))
            } catch (e: Exception) {
                continue
            } ?: continue // not relay-fronted, or not answered: nothing to check now
            val local = localPin(box.aliasId)
            val mac: (ByteArray) -> ByteArray = { fortress.keys.macWith(FortressOpener.SCOPE, "sealed-vault:pin", it) }
            val verdict = RelayPinCheck.judge(answer, box.aliasId, box.address, mac, listOf(myKey), local)
            if (verdict.ok) {
                rememberPin(box.aliasId, verdict.identity ?: continue)
            } else {
                alarm = RelayAlarm(box.address, verdict.reason ?: "unreadable", verdict.expected, verdict.reported)
            }
        }
    }

    private val pinPrefs by lazy { context.getSharedPreferences("joinery.relaypins", Context.MODE_PRIVATE) }
    private fun localPin(aliasId: Int): String? = pinPrefs.getString("joinery-relay-pin:$aliasId", null)
    private fun rememberPin(aliasId: Int, identity: String) {
        pinPrefs.edit().putString("joinery-relay-pin:$aliasId", identity).apply()
    }

    // MARK: 3. AI on the person's own model

    private fun onWifi(): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return false
        val caps = cm.getNetworkCapabilities(cm.activeNetwork ?: return false) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED) ||
            caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) || caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET)
    }

    /** A judge whose model calls may go only to [origin], the account's registered one. */
    fun judge(origin: String): AiJudge = AiJudge(
        open = { row -> fortress.opener.openFields(row) },
        keyOf = { row -> fortress.opener.dek(row) },
        transport = { url, headers, body -> client.postExternal(url, origin, body, headers) },
        post = { action, body -> post(action, body) },
        epoch = { fortress.lockEpoch },
    )

    suspend fun drainAi(store: MailboxStore): Int {
        val epoch = fortress.lockEpoch
        if (aiStoppedEpoch == epoch || aiJudged >= DRAIN_CAP) return 0
        val saved = aiSettings.load() ?: return 0
        if (saved.wifiOnly && !onWifi()) {
            status = "AI waits for Wi-Fi."
            return 0
        }
        var judged = 0
        val boxes = store.home?.mailboxes?.filter { it.isFortress } ?: return 0
        for (box in boxes) {
            val info = try { post("mailbox/ai_device_recipes", JsonValue.obj("mailbox" to JsonValue.Str(box.address))) } catch (e: Exception) { null } ?: continue
            val origin = info["device_ai_origin"]?.takeUnless { it.isNull }?.stringValue ?: continue
            val url = DeviceAiSettings.callUrl(origin, saved.path) ?: continue
            if (info["consent_refusal"]?.takeUnless { it.isNull }?.stringValue?.isNotEmpty() == true) continue
            val endpoint = AiJudge.Endpoint(url, saved.key, saved.model)
            val judge = judge(origin)
            val authserv = info["authserv_id"] ?: JsonValue.Str("")
            for (recipe0 in info["recipes"]?.arrayValue ?: emptyList()) {
                val recipe = JsonValue.Obj(listOf("authserv_id" to authserv) + (recipe0.objectValue ?: emptyList()))
                var before = 0
                do {
                    val page = post("mailbox/device_ai_entries", JsonValue.obj(
                        "recipe_id" to (recipe["recipe_id"] ?: JsonValue.Null),
                        "alias_id" to (info["alias_id"] ?: JsonValue.Null),
                        "before_id" to JsonValue.Num(before.toDouble()),
                    )) ?: break
                    for (entry in page["entries"]?.arrayValue ?: emptyList()) {
                        val entryId = entry["id"]?.intValue ?: continue
                        if (entryId in aiUnopenable) continue
                        if (aiJudged >= DRAIN_CAP) { status = "$DRAIN_CAP judged; more next time."; return judged }
                        if (!fortress.isOpen) return judged
                        status = "Judging your mail with ${saved.model}… $aiJudged done."
                        when (val out = judge.judge(entry, recipe, endpoint)) {
                            is AiJudge.Outcome.Dropped -> { status = null; return judged }
                            is AiJudge.Outcome.Stop -> {
                                aiStoppedEpoch = epoch
                                status = "Your model did not answer${out.http?.let { " ($it)" } ?: ""}: " +
                                    (if (out.reason == "unreachable") "it could not be reached." else out.reason) +
                                    " AI waits until it does."
                                return judged
                            }
                            is AiJudge.Outcome.Done -> { aiJudged++; judged++ }
                            is AiJudge.Outcome.Unopenable -> aiUnopenable.add(entryId)
                            is AiJudge.Outcome.Error -> aiJudged++
                        }
                    }
                    before = page["next_before_id"]?.takeUnless { it.isNull }?.intValue ?: 0
                } while (before > 0)
            }
        }
        if (status?.startsWith("Judging") == true) status = null
        return judged
    }

    // MARK: 4. Rules on existing mail

    suspend fun ruleBacklog(): Int {
        val epoch0 = fortress.lockEpoch
        var applied = 0
        var pages = 0
        while (fortress.isOpen && fortress.lockEpoch == epoch0 && pages < 200) {
            pages++
            val page = post("mailbox/rule_backlog", JsonValue.Obj(emptyList())) ?: break
            val rule = page["rule"]?.takeUnless { it.isNull } ?: break
            val throughId = page["through_id"]?.intValue ?: break
            val matched = ArrayList<JsonValue>()
            for (row in page["rows"]?.arrayValue ?: emptyList()) {
                val id = row["id"]?.intValue ?: continue
                val sealed = row["sealed"]?.let { SealedRow.from(it) }?.let { if (it.key == 0) it.copy(key = id) else it } ?: continue
                val o = try { fortress.opener.openFields(sealed) } catch (e: Exception) { continue }
                val message = FilterMatch.message(
                    o["iem_sender"] ?: "", (o["iem_recipient"] ?: "").ifEmpty { row["recipient"]?.stringValue ?: "" },
                    o["iem_subject"] ?: "", o["iem_body_plain"] ?: "", o["iem_body_html"] ?: "",
                    (row["size_bytes"]?.intValue ?: 0).toLong(), row["has_attachment"]?.boolValue == true,
                )
                if (FilterMatch.matches(rule, message)) matched.add(JsonValue.Num(id.toDouble()))
            }
            if (fortress.lockEpoch != epoch0) break
            val out = post("mailbox/rule_outcomes", JsonValue.obj(
                "rule_id" to (rule["id"] ?: JsonValue.Null),
                "through_id" to JsonValue.Num(throughId.toDouble()),
                "matched_ids" to JsonValue.Str(JsonValue.Arr(matched).encoded()),
            ))
            applied += out?.get("applied")?.intValue ?: 0
        }
        return applied
    }

    companion object {
        const val DRAIN_CAP = 200
        const val MIN_GAP_MS = 10_000L

        @Volatile
        private var instance: FortressWork? = null

        /** Sign-out: this session's work stops, and its skips are forgotten. */
        fun reset() {
            synchronized(this) {
                instance?.let {
                    it.running?.cancel()
                    it.relayGuard.clear()
                }
                instance = null
            }
        }

        fun get(context: Context, client: ApiClient, fortress: FortressMail): FortressWork {
            instance?.let { if (it.client === client) return it }
            synchronized(this) {
                instance?.let { if (it.client === client) return it }
                return FortressWork(context.applicationContext, client, fortress).also {
                    instance = it
                    // Skips belong to the key that could not open them (R12).
                    fortress.onKeyWiped = { it.relayGuard.clear() }
                }
            }
        }
    }
}
