<?php
/**
 * SupportTurn — run one support turn onto its assistant placeholder row and
 * finish it: the support desk's counterpart of joinery_ai's ChatTurn, over the
 * same AgentLoop, the same message rows and the same live status columns
 * (specs/support_chatbot.md, The engine).
 *
 * The detached worker (cli/run_support_turn.php) calls runAndFinalize(); a
 * test calls it with a stand-in resolution. Every failure lands on the row as
 * FAILED with a sentence the asking person can read, never a provider's error
 * text (that goes to the worker log).
 *
 * @version 0.1.2 - answers reason at medium, not the chat default
 * @version 0.1.1 - cached tokens on their own columns, and the turn's cost
 * @version 0.1.0
 */
class SupportTurn {

	/** Model calls one turn may make. */
	const MAX_ITERATIONS = 12;

	/** Output tokens one turn may generate. */
	const OUTPUT_BUDGET = 8000;

	/** What the person reads after the model handed the thread to a person. */
	const ESCALATED_SENTENCE = 'A person on the support desk will reply in this thread. You can close this panel; the Help button shows a mark when they do.';

	/**
	 * Run the turn and write its result onto $assistant. $resolution stands in
	 * for the model in a test; production resolves from the plugin's setting.
	 * Returns what the provider reported (input, cache_write, cache_read,
	 * output tokens, model, stop reason), or null when the turn failed.
	 */
	public static function runAndFinalize(SupportThread $thread, AiConversationMessage $assistant, ?AiModelResolution $resolution = null): ?array {
		$stamper = ChatAsync::activityStamper($assistant);
		$stamper('Starting…');

		$conversation = $thread->conversation();
		if ($conversation === null) {
			self::markFailed($assistant, 'This thread has no transcript.');
			return null;
		}
		list($release, $exact) = self::releaseFor($thread);
		if ($release === null) {
			self::markFailed($assistant, 'The support desk has no documentation indexed yet. Try again later.');
			return null;
		}

		$ctx = new SupportTurnContext($thread, (int)$assistant->key, $release, $exact);
		$ctx->setStreamSink(ChatAsync::streamSink($assistant));
		$ctx->setActivityStamper($stamper);
		$tools = SupportTools::forThread($thread);

		try {
			$resolution = $resolution ?? self::resolve();
			$system = SupportPrompt::systemBlocks($thread, $release, $exact, $tools);
			$messages = self::history($conversation, $thread, (int)$assistant->key);
			if (!$messages) {
				self::markFailed($assistant, 'There is no question to answer.');
				return null;
			}
			$result = AgentLoop::run($resolution, $system, $messages, $tools, $ctx,
				self::MAX_ITERATIONS, self::OUTPUT_BUDGET);
		} catch (LlmProviderException $e) {
			ChatAsync::log('[support] provider error on message #' . (int)$assistant->key . ': ' . $e->getMessage());
			self::markFailed($assistant, 'The support desk could not reach its model just now. Try again in a few minutes.');
			return null;
		} catch (\Throwable $e) {
			ChatAsync::log('[support] turn failed on message #' . (int)$assistant->key . ': ' . get_class($e) . ': ' . $e->getMessage());
			self::markFailed($assistant, 'The support desk could not answer this one. Try again, or ask for a person.');
			return null;
		}

		$text = trim((string)($result['assistant_text'] ?? ''));
		if ($text === '') {
			$text = self::stopNote((string)($result['stop_reason'] ?? ''));
		}
		if (self::escalated($ctx->toolCalls()) && strpos($text, self::ESCALATED_SENTENCE) === false) {
			$text = rtrim($text) . "\n\n" . self::ESCALATED_SENTENCE;
		}

		$cache_read = (int)($result['cache_read_tokens'] ?? 0);
		$cache_write = (int)($result['cache_write_tokens'] ?? 0);
		$in = (int)$result['input_tokens'] + $cache_write + $cache_read;
		$out = (int)$result['output_tokens'];
		AiConversationMessage::updateColumns((int)$assistant->key, array(
			'aim_content'            => $text,
			'aim_tool_calls'         => json_encode($ctx->toolCalls(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'aim_input_tokens'       => min(2147483647, (int)$result['input_tokens']),
			'aim_cache_read_tokens'  => min(2147483647, $cache_read),
			'aim_cache_write_tokens' => min(2147483647, $cache_write),
			'aim_cost_estimate'      => ChatTurn::costOf($result),
			'aim_output_tokens'      => $out,
			'aim_context_used'     => (int)($result['context_tokens'] ?? 0),
			'aim_status'           => (($result['stop_reason'] ?? '') === 'cancelled')
				? AiConversationMessage::STATUS_CANCELLED : AiConversationMessage::STATUS_COMPLETE,
			'aim_activity'         => null,
			'aim_cancel_requested' => false,
		));
		SupportThread::addTokens((int)$thread->key, $in, $out);
		SupportThread::touch((int)$thread->key);
		AiConversation::updateColumns((int)$conversation->key, array(
			'aic_total_input_tokens'  => (int)$conversation->get('aic_total_input_tokens') + $in,
			'aic_total_output_tokens' => (int)$conversation->get('aic_total_output_tokens') + $out,
			'aic_update_time'         => gmdate('Y-m-d H:i:s'),
		));
		SupportGaps::noteRefusedWords($thread, $ctx->toolCalls());
		// What the provider reported about its cache, for the standing check
		// and the admin page: written to the worker log, one line a turn.
		ChatAsync::log('[support] thread #' . (int)$thread->key . ' message #' . (int)$assistant->key
			. ' release ' . $release . ' model ' . ($result['model'] ?? '?')
			. ' in ' . (int)$result['input_tokens'] . ' cache_write ' . (int)($result['cache_write_tokens'] ?? 0)
			. ' cache_read ' . (int)($result['cache_read_tokens'] ?? 0) . ' out ' . $out
			. ' stop ' . ($result['stop_reason'] ?? ''));
		return array(
			'input_tokens'       => (int)$result['input_tokens'],
			'cache_write_tokens' => (int)($result['cache_write_tokens'] ?? 0),
			'cache_read_tokens'  => (int)($result['cache_read_tokens'] ?? 0),
			'output_tokens'      => $out,
			'model'              => (string)($result['model'] ?? ''),
			'stop_reason'        => (string)($result['stop_reason'] ?? ''),
		);
	}

	/** The thinking level every desk answer runs at, whatever the site's
	 *  chat default says. */
	const THINKING_LEVEL = 'medium';

	/** The model the desk answers with. */
	public static function resolve(): AiModelResolution {
		return AiModelResolver::resolve(self::requirement());
	}

	/** What the desk asks of its model: the pinned support_desk_model, tools,
	 *  and reasoning at THINKING_LEVEL. */
	public static function requirement(): AiModelRequirement {
		$pin = trim((string)Globalvars::get_instance()->get_setting('support_desk_model', true, true));
		return AiModelRequirement::make()
			->withMinTier(AiModelRequirement::TIER_BASIC)
			->withTrustFloor(AiModelRequirement::TRUST_ANY)
			->withTools(true)
			->withPin($pin)
			->withThinkingLevel(self::THINKING_LEVEL)
			->withPolicy(AiModelRequirementBuilder::sitePolicy())
			->withPurpose('the support desk');
	}

	/** The release a thread reads: the site's own (or nearest) release; for the widget, the newest. */
	public static function releaseFor(SupportThread $thread): array {
		if ((string)$thread->get('sth_surface') === SupportThread::SURFACE_WIDGET) {
			$all = SupportDocRelease::releases();
			return $all ? array($all[0], true) : array(null, false);
		}
		return SupportDocIndex::releaseFor((string)$thread->get('sth_version'));
	}

	/**
	 * The transcript as model messages, up to (not including) the running row.
	 * A person's reply is an assistant turn marked as theirs; the newest
	 * question carries the page it was asked from; the last message carries a
	 * cache breakpoint so the next turn reads the conversation so far from
	 * cache.
	 */
	public static function history(AiConversation $conversation, SupportThread $thread, int $running_id): array {
		$rows = new MultiAiConversationMessage(
			array('conversation_id' => (int)$conversation->key, 'deleted' => false),
			array('aim_conversation_message_id' => 'ASC'));
		$out = array();
		foreach ($rows as $row) {
			if ((int)$row->key >= $running_id && $running_id > 0) {
				break;
			}
			$role = (string)$row->get('aim_role');
			$text = trim((string)$row->get('aim_content'));
			if ($text === '') {
				continue;
			}
			if ($role === AiConversationMessage::ROLE_ASSISTANT) {
				$status = (string)$row->get('aim_status');
				if ($status === AiConversationMessage::STATUS_FAILED || $status === AiConversationMessage::STATUS_RUNNING) {
					continue;
				}
				if ((int)$row->get('aim_author') > 0) {
					$text = "[A person on the support desk wrote this reply.]\n" . $text;
				}
				$out[] = array('role' => 'assistant', 'content' => $text);
			} elseif ($role === AiConversationMessage::ROLE_USER) {
				$out[] = array('role' => 'user', 'content' => $text);
			}
		}
		// Alternate strictly, starting with the person.
		$merged = array();
		foreach ($out as $m) {
			if (!$merged && $m['role'] === 'assistant') {
				continue;
			}
			$last = count($merged) - 1;
			if ($last >= 0 && $merged[$last]['role'] === $m['role']) {
				$merged[$last]['content'] .= "\n\n" . $m['content'];
			} else {
				$merged[] = $m;
			}
		}
		// A turn answers the person's newest message; with none waiting there is nothing to send.
		if (!$merged || $merged[count($merged) - 1]['role'] !== 'user') {
			return array();
		}
		$i = count($merged) - 1;
		$route = self::route((string)$thread->get('sth_route'));
		$latest = $merged[$i]['content'];
		if ($route !== '') {
			$latest = '(Asked from ' . $route . ")\n\n" . $latest;
		}
		$merged[$i]['content'] = array(array('type' => 'text', 'text' => $latest,
			'cache_control' => AiPromptBuilder::cacheControl()));
		return $merged;
	}

	/** A route as the model sees it: a path or a setup step, nothing else. */
	public static function route(string $route): string {
		$route = trim($route);
		if (preg_match('#^setup:[a-z0-9_]{1,64}$#', $route)) {
			return 'the setup wizard step "' . substr($route, 6) . '"';
		}
		if (preg_match('#^/[A-Za-z0-9_\-/]{0,200}$#', $route)) {
			return 'the page ' . $route;
		}
		return '';
	}

	private static function escalated(array $tool_calls): bool {
		foreach ($tool_calls as $tc) {
			if (($tc['name'] ?? '') === 'escalate' && empty($tc['is_error'])) {
				return true;
			}
		}
		return false;
	}

	/** What the person reads when the loop stopped without an answer. */
	public static function stopNote(string $stop_reason): string {
		switch ($stop_reason) {
			case 'cancelled':      return '_(Cancelled.)_';
			case 'wall_clock':     return '_(That took too long. Ask again, or ask for a person.)_';
			case 'max_iterations':
			case 'token_budget':   return '_(I ran out of room for one answer. Ask me to continue.)_';
			case 'tool_errors':    return '_(I ran into repeated errors reading what I needed. Ask for a person if this keeps happening.)_';
			default:               return '_(No answer was written. Try again.)_';
		}
	}

	public static function markFailed(AiConversationMessage $msg, string $error): void {
		AiConversationMessage::updateColumns((int)$msg->key, array(
			'aim_status'           => AiConversationMessage::STATUS_FAILED,
			'aim_error'            => $error,
			'aim_activity'         => null,
			'aim_cancel_requested' => false,
		));
	}
}
