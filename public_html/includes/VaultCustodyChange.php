<?php
/**
 * VaultCustodyChange - moving rows off a client-custody vault
 * (specs/client_custody_mail.md § R8, the lowering).
 *
 * A row sealed to a client-custody scope (Fortress mail) has a DEK only its
 * owner's browser opens, so only that browser can move it anywhere else. When
 * a row's hook stops naming the scope (a mailbox lowered from Fortress to
 * Private), the browser walks the rows the hook moved, opens each DEK with the
 * scope's session and re-seals it to the vault the hook now names; the server
 * checks and stores the result (SystemBase::acceptBrowserCustodyChange()).
 * Content stays as it is: the DEK does not change, only whose key wraps it.
 *
 * Which models take part is what VaultUnlock::clientReseal() registered for
 * the scope, the same list a rotation re-seals. The walk resumes wherever it
 * stopped, since a moved row is no longer listed.
 *
 * The raise (server custody to client) is the server's work, done row by row
 * in the owner's window: SystemBase::convertRowToClientCustody().
 *
 * @version 1.0
 */
class VaultCustodyChange {

	/** Rows per page of the walk, and per accept request. */
	const PAGE_MAX = 100;

	/**
	 * One page of the caller's rows to move off client-custody $scope, across
	 * the registered models in order. $model/$after_id is the cursor the
	 * previous page returned; an empty $model starts at the first model, and
	 * that first page also carries `remaining` when every model can count
	 * cheaply (backlog(); later pages carry null). A page may be empty with a
	 * `next`: keep walking.
	 * Each row names the vault it moves to and that vault's public key, in the
	 * standard base64 the browser seals to.
	 *
	 * @return array{rows:array, next:?array{model:string,after_id:int}, remaining:?int}
	 */
	public static function page(int $user_id, string $scope, string $model, int $after_id, int $limit): array {
		self::assertScopeVault($user_id, $scope);
		$classes = VaultUnlock::clientResealsFor($scope)['classes'];
		$limit = max(1, min(self::PAGE_MAX, $limit));
		$remaining = null;
		if ($model === '' && $after_id === 0) {
			$remaining = self::backlog($user_id, $scope);
		}

		$start = ($model === '') ? 0 : array_search($model, $classes, true);
		if ($start === false) {
			throw new VaultClientCustodyException('Unknown model in the custody cursor.');
		}
		$keys = array();   // target scope => its public key, loaded once per request
		for ($i = $start; $i < count($classes); $i++) {
			$class = $classes[$i];
			try {
				$page = $class::browserCustodyPage($user_id, $scope, ($i === $start) ? $after_id : 0, $limit);
			} catch (RuntimeException $e) {
				throw new VaultClientCustodyException($e->getMessage());
			}
			if (!$page['rows'] && $page['done']) {
				continue;
			}
			$rows = array();
			foreach ($page['rows'] as $r) {
				$target = $r['target_scope'];
				if (!array_key_exists($target, $keys)) {
					$keys[$target] = self::targetPublicKey($user_id, $target);
				}
				$rows[] = array('model' => $class, 'id' => $r['id'], 'sealed_dek' => $r['sealed_dek'],
					'target_scope' => $target, 'target_public_key' => $keys[$target]);
			}
			$next = (!$page['done'] || $i + 1 < count($classes))
				? ($page['done'] ? array('model' => $classes[$i + 1], 'after_id' => 0)
					: array('model' => $class, 'after_id' => $page['last_id']))
				: null;
			return array('rows' => $rows, 'next' => $next, 'remaining' => $remaining);
		}
		return array('rows' => array(), 'next' => null, 'remaining' => $remaining);
	}

	/**
	 * How many of the caller's rows under $scope are waiting to move, or null
	 * when a model cannot say without reading every row
	 * (SystemBase::browserCustodyBacklog()).
	 */
	public static function backlog(int $user_id, string $scope): ?int {
		VaultClientCustody::assertClientScope($scope);
		$count = 0;
		foreach (VaultUnlock::clientResealsFor($scope)['classes'] as $class) {
			$n = $class::browserCustodyBacklog($user_id, $scope);
			if ($n === null) {
				return null;
			}
			$count += $n;
		}
		return $count;
	}

	/**
	 * Store re-sealed DEKs, [{model, id, sealed_dek}], for rows leaving
	 * $scope. Each model must be registered for $scope; each row must be the
	 * caller's and its hook must name the vault the key is sealed to.
	 */
	public static function accept(int $user_id, string $scope, array $rows): int {
		VaultClientCustody::assertClientScope($scope);
		if (count($rows) > self::PAGE_MAX) {
			throw new VaultClientCustodyException('Too many rows in one request.');
		}
		$classes = VaultUnlock::clientResealsFor($scope)['classes'];
		$written = 0;
		foreach ($rows as $r) {
			$model = (string)($r['model'] ?? '');
			if (!in_array($model, $classes, true)) {
				throw new VaultClientCustodyException('Nothing named ' . $model . ' is kept in this vault.');
			}
			try {
				$model::acceptBrowserCustodyChange($user_id, (int)($r['id'] ?? 0), (string)($r['sealed_dek'] ?? ''));
			} catch (RuntimeException $e) {
				throw new VaultClientCustodyException($e->getMessage());
			}
			$written++;
		}
		return $written;
	}

	/** The caller must hold the scope's vault: its session opens the DEKs being moved. */
	private static function assertScopeVault(int $user_id, string $scope): void {
		if (!VaultClientCustody::loadVault($user_id, $scope)) {
			throw new VaultClientCustodyException('Your vault is not set up.');
		}
	}

	/**
	 * The public key a row moving to $scope is sealed to, standard base64. A
	 * client-custody vault's key is stored that way already (the pending one
	 * during a rotation); a server vault's is base64url.
	 */
	private static function targetPublicKey(int $user_id, string $scope): string {
		$vault = UserEncryptionVault::loadForUser($user_id, $scope);
		if (!$vault) {
			throw new VaultClientCustodyException('There is no "' . $scope . '" vault to move these to.');
		}
		if ((string)$vault->get('uev_custody') === 'client') {
			return $vault->sealingPublicKey();
		}
		return base64_encode(SealedBox::b64url_decode((string)$vault->get('uev_public_key')));
	}
}
?>
