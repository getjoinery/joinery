<?php

/**
 * Scheduling provider seam.
 *
 * The native engine is one implementation; external services (Calendly, Acuity)
 * slot in later without reopening this contract. Two integration modes, declared
 * per provider by getMode():
 *
 *   - 'headless' — Joinery renders its own slot picker and booking form; the
 *     provider is just the availability/booking backend. The invitee never sees
 *     the provider's UI. (Native is headless.)
 *   - 'embed' — the booking page embeds the provider's widget; webhooks ingest
 *     the resulting booking into bkn_bookings.
 *
 * The booking page branches once on mode; everything downstream (booking rows,
 * calendar items, admin, analytics) is provider-agnostic.
 *
 * NOTE: `$conn` parameters are a ProviderConnection — a model defined by the
 * external-integrations spec, not built here. It is intentionally left untyped
 * so the native engine carries no dependency on an unbuilt class. Headless
 * native ignores every connection/embed/webhook method.
 */
interface SchedulingServiceProvider {

	public static function getKey(): string;          // 'native' | 'calendly' | 'acuity'
	public static function getLabel(): string;
	/** 'headless' | 'embed' */
	public static function getMode(): string;

	/** Connection fields for API-key providers; OAuth providers return [] and supply a connect URL. */
	public static function getConnectionFields(): array;
	public static function getConnectUrl(?int $user_id): ?string;   // OAuth consent URL or null

	/** Event types from the provider, for the import/link UI. ($conn: ProviderConnection) */
	public function listEventTypes($conn): array;

	// Headless mode only — embed providers never receive these calls:
	public function getAvailableSlots(BookingType $type, string $start_utc, string $end_utc): array;
	/** ($invitee: ['email','name','notes','timezone',...]) -> Booking */
	public function createBooking(BookingType $type, array $invitee, string $slot_start_utc): Booking;
	public function cancelBooking(Booking $booking, string $reason): bool;

	// Embed mode only:
	public function getEmbedHtml(BookingType $type, array $tracking): string;

	// Change ingestion (any provider that pushes updates). ($conn: ProviderConnection)
	public function registerWebhooks($conn): void;
	public function verifyWebhook(array $headers, string $raw_body, $conn): bool;
	public function handleWebhook(array $payload, $conn): void;
}
