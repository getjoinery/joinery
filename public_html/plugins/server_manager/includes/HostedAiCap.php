<?php
/**
 * HostedAiCap — the arithmetic of a site's AI credit, and nothing else
 * (specs/hosted_ai_credit.md §3.1).
 *
 * Pure functions over microdollars of TrustedRouter spend, so every case of
 * the money model is a unit test with no database and no provider:
 *
 *   U   the key's lifetime usage, as TrustedRouter reports it
 *   U0  that usage when the current month began
 *   A   this month's free usage (at most F, the monthly free figure)
 *   B   bought credit left at the month's start, plus any bought since
 *   D   spend past the cap that nothing has paid for yet
 *
 * The key's cap is always U0 + A + B. Spend this month, S = U − U0, comes out
 * of A first and then B.
 *
 * FREE USAGE IS A TOP-UP, NEVER AN ADDITION (owner, 2026-11-26). Each month A
 * is brought up to F, not increased by F; unused free usage does not carry
 * over; and spend past the cap that nothing paid for (the in-flight overshoot
 * TrustedRouter's non-strict mode allows) is carried as D and taken out of the
 * next top-ups, so over time no site gets more than F of free usage a month.
 *
 * @version 1.0
 */

class HostedAiCapException extends Exception {}

class HostedAiCap {

	/** One dollar, in microdollars: the ceiling on the monthly free figure (owner's rule, not a tuning figure). */
	const MAX_FREE = 1000000;

	/** The cap a key should stand at. */
	public static function cap(int $u0, int $allowance, int $purchased): int {
		return max(0, $u0) + max(0, $allowance) + max(0, $purchased);
	}

	/** Spend this month. */
	public static function spent(int $usage, int $u0): int {
		return max(0, $usage - $u0);
	}

	/** How much of this month's spend the free usage covered. */
	public static function allowance_used(int $spent, int $allowance): int {
		return min(max(0, $spent), max(0, $allowance));
	}

	/** How much of this month's spend came out of bought credit. */
	public static function purchased_used(int $spent, int $allowance): int {
		return max(0, $spent - max(0, $allowance));
	}

	/** Bought credit not yet spent. */
	public static function unspent_purchased(int $spent, int $allowance, int $purchased): int {
		return max(0, $purchased - self::purchased_used($spent, $allowance));
	}

	/**
	 * The month boundary. Returns the new figures:
	 * ['u0' => …, 'allowance' => …, 'purchased' => …, 'owed' => …, 'over' => …].
	 *
	 * @param int  $usage     U, lifetime usage read at the roll
	 * @param int  $free      F, the monthly free figure
	 * @param bool $entitled  whether the site's plan still gives it free usage
	 */
	public static function roll(int $usage, int $u0, int $allowance, int $purchased, int $owed,
			int $free, bool $entitled): array {
		self::assert_free($free);
		$spent = self::spent($usage, $u0);
		$over = max(0, $spent - $allowance - $purchased);
		$purchased_after = max(0, $purchased - self::purchased_used($spent, $allowance));
		$owed_after = max(0, $owed) + $over;
		if ($entitled) {
			$allowance_after = max(0, $free - $owed_after);
			$owed_after = max(0, $owed_after - $free);
		} else {
			$allowance_after = 0;
		}
		return array(
			'u0'        => max($usage, $u0),
			'allowance' => $allowance_after,
			'purchased' => $purchased_after,
			'owed'      => $owed_after,
			'over'      => $over,
		);
	}

	/**
	 * The credit a payment of $money dollars buys at $markup_percent: money ÷ (1 + m).
	 * A $20 purchase at 10% is $18.181818 of spend.
	 */
	public static function credit_for_money(float $money, float $markup_percent): int {
		if ($money < 0) {
			throw new HostedAiCapException('A payment is not negative.');
		}
		if ($markup_percent < 0) {
			throw new HostedAiCapException('A markup is not negative.');
		}
		return (int)floor(round($money * 1000000 * 100 / (100 + $markup_percent), 3));
	}

	/**
	 * How much credit a refund of $money takes off, at the markup recorded on
	 * the purchase it refunds, never more than the bought credit not yet spent.
	 * Spent credit is gone; taking it off again would leave the site owing it.
	 */
	public static function credit_for_refund(float $money, float $markup_percent, int $unspent): int {
		return min(self::credit_for_money($money, $markup_percent), max(0, $unspent));
	}

	/** Refuse a monthly free figure above $1. */
	public static function assert_free(int $free): void {
		if ($free < 0 || $free > self::MAX_FREE) {
			throw new HostedAiCapException('The monthly free AI usage is between $0 and $1.00.');
		}
	}

	/** Dollars, as typed in a setting, to microdollars. */
	public static function micro(string $dollars): int {
		$dollars = trim($dollars);
		if ($dollars === '' || !is_numeric($dollars)) {
			throw new HostedAiCapException("'{$dollars}' is not a dollar amount.");
		}
		return (int)round((float)$dollars * 1000000);
	}

	/** Microdollars as dollars for people: $1.23 (or $0.0042 below a cent). */
	public static function dollars(int $micro): string {
		$d = $micro / 1000000;
		if ($micro !== 0 && abs($d) < 0.01) {
			return ($d < 0 ? '-' : '') . '$' . rtrim(rtrim(number_format(abs($d), 4, '.', ''), '0'), '.');
		}
		return ($d < 0 ? '-' : '') . '$' . number_format(abs($d), 2);
	}

	/** The monthly free figure F from its setting, refusing one above $1. */
	public static function free_from_settings(): int {
		$raw = (string)Globalvars::get_instance()->get_setting('server_manager_hosted_ai_allowance_usd', true, true);
		$free = self::micro($raw === '' ? '1.00' : $raw);
		self::assert_free($free);
		return $free;
	}

	/** The markup on bought credit, percent, from its setting. */
	public static function markup_from_settings(): float {
		$raw = trim((string)Globalvars::get_instance()->get_setting('server_manager_hosted_ai_markup_percent', true, true));
		$m = $raw === '' ? 10.0 : (float)$raw;
		if (!is_numeric($raw === '' ? '10' : $raw) || $m < 0 || $m > 1000) {
			throw new HostedAiCapException('The AI credit markup is a percentage from 0 to 1000.');
		}
		return $m;
	}
}
