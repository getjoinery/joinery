<?php
/**
 * SupportTurnContext — the run context of one support turn over joinery_ai's
 * AgentLoop (specs/support_chatbot.md, The engine).
 *
 * The turn acts as the support desk's reserved user and reads nothing of this
 * node's own data: no model scope, no actions, no sealed content, no memory.
 * The tools it offers are fixed by the thread (SupportPrompt::toolsFor()) and
 * none of them writes, so queuesWrites() is false and enqueueProposedAction()
 * refuses whatever reaches it.
 *
 * The untrusted nonce is the thread's own (sth_nonce), not a fresh one per
 * turn: the thread's facts sit after the fixed prefix in a block the provider
 * caches, and a nonce that changed every turn would make that block new every
 * turn. It never leaves this node.
 *
 * The cancel flag and the live status are the chat's: the assistant row's
 * aim_cancel_requested (written by the cancel action in another process) and
 * aim_activity. The tool trace accumulates here and is saved on the assistant
 * row when the turn finishes.
 *
 * @version 0.1.0
 */
class SupportTurnContext implements ToolContext {

	/** Per-turn wall clock. A node read can wait a minute, and a turn may make a few. */
	const PER_TURN_SECONDS = 420;

	/** @var SupportThread */
	private $thread;

	/** @var int the RUNNING assistant row (0 when a test drives the loop without one) */
	private $message_id;

	/** @var string the release whose documents this turn searches */
	private $release;

	/** @var bool the release is the site's own (false: the nearest indexed one) */
	private $release_exact;

	private $started;
	private $tool_calls = array();
	private $stream_sink = null;
	private $activity_stamper = null;

	public function __construct(SupportThread $thread, int $message_id, string $release, bool $release_exact) {
		$this->thread = $thread;
		$this->message_id = $message_id;
		$this->release = $release;
		$this->release_exact = $release_exact;
		$this->started = microtime(true);
	}

	// --- support-only accessors (tools reach these through the concrete class)

	public function thread(): SupportThread {
		return $this->thread;
	}

	public function release(): string {
		return $this->release;
	}

	public function releaseExact(): bool {
		return $this->release_exact;
	}

	public function messageId(): int {
		return $this->message_id;
	}

	// --- ToolContext

	public function actingUserId(): int {
		return SupportDesk::systemUserId();
	}

	public function ownerTimezone(): string {
		return 'UTC';
	}

	public function untrustedNonce(): string {
		return (string)$this->thread->get('sth_nonce');
	}

	public function allowedModels(): array {
		return array();
	}

	public function allowedActions(): array {
		return array();
	}

	public function queuesWrites(): bool {
		return false;
	}

	public function enqueueProposedAction(array $tool_use): array {
		return array('type' => 'tool_result', 'tool_use_id' => (string)($tool_use['id'] ?? ''),
			'content' => 'The support desk never changes anything on a site.', 'is_error' => true);
	}

	public function executesInline(string $tool_name): bool {
		return false;
	}

	public function writeProvenance(): string {
		return 'support thread #' . (int)$this->thread->key;
	}

	public function ownerScopedReads(?string $model_class = null): bool {
		return true;
	}

	public function sealedReadsAllowed(): bool {
		return false;
	}

	public function shouldContinue(): ?array {
		if ($this->isCancelRequested()) {
			return array('stop_reason' => 'cancelled', 'detail' => 'cancelled by the person');
		}
		if (microtime(true) - $this->started > self::PER_TURN_SECONDS) {
			return array('stop_reason' => 'wall_clock', 'detail' => 'per-turn timeout');
		}
		return null;
	}

	public function shouldAbort(): bool {
		return $this->isCancelRequested();
	}

	/** Has the person cancelled this turn? Read fresh: the cancel action runs in another process. */
	public function isCancelRequested(): bool {
		if ($this->message_id <= 0) {
			return false;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT aim_cancel_requested FROM aim_conversation_messages WHERE aim_conversation_message_id = ?');
		$q->execute(array($this->message_id));
		return (bool)$q->fetchColumn();
	}

	public function beginToolCall(array $entry): void {
		$this->tool_calls[] = $entry;
		$label = SupportTools::activityLabel((string)($entry['name'] ?? ''), (array)($entry['input'] ?? array()));
		if ($label !== '') {
			$this->noteActivity($label);
		}
	}

	public function finishToolCall(array $entry): void {
		for ($i = count($this->tool_calls) - 1; $i >= 0; $i--) {
			if (($this->tool_calls[$i]['name'] ?? '') === ($entry['name'] ?? '')
					&& ($this->tool_calls[$i]['started_time'] ?? '') === ($entry['started_time'] ?? '')) {
				$this->tool_calls[$i] = $entry;
				return;
			}
		}
		$this->tool_calls[] = $entry;
	}

	public function appendToolCall(array $entry): void {
		$this->tool_calls[] = $entry;
	}

	public function setStreamSink(callable $sink): void {
		$this->stream_sink = $sink;
	}

	public function emitText(string $delta): void {
		if ($this->stream_sink !== null) {
			($this->stream_sink)($delta);
		}
	}

	public function setActivityStamper(callable $stamper): void {
		$this->activity_stamper = $stamper;
	}

	public function noteActivity(string $label): void {
		if ($this->activity_stamper !== null) {
			($this->activity_stamper)($label);
		}
	}

	public function toolCalls(): array {
		return $this->tool_calls;
	}
}
