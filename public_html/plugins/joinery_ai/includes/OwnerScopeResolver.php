<?php
/**
 * Resolves, for one data model, how a non-admin member's reads are contained
 * to their own rows. Admins are never owner-scoped (they legitimately need
 * cross-user views), so this resolver only describes the member-caller fence;
 * the read executor consults it solely when ToolContext::ownerScopedReads() is
 * true.
 *
 * The decision is a pure function of the model's columns and an optional
 * declaration, $ai_owner_field, on the class:
 *
 *   - unset            infer the owner column: the lone column whose name ends
 *                      in _usr_user_id or _owner_user_id. Exactly one match is
 *                      owner-scoped on it. Zero or two-plus candidates is
 *                      ambiguous, and ambiguous ownership is never guessed — the
 *                      model resolves to HIDDEN (fail-closed).
 *   - string           that column is the owner column (e.g. a primary key like
 *                      users.usr_user_id that the convention can't infer).
 *   - array of strings OR-match across the columns (e.g. messages =
 *                      sender-or-recipient). A member sees a row if they own any.
 *   - ['polymorphic' => ['type_column'=>.., 'id_column'=>.., 'type_value'=>..]]
 *                      the owner is a (type, id) pair rather than a single FK-
 *                      style column (e.g. CalendarEntry/Schedule, owned by a
 *                      CalendarSubject). Scopes WHERE type_column = type_value
 *                      AND id_column = me.
 *   - false            ownerless catalog/config (products, pages, …). Members
 *                      read every row; there is nothing to contain.
 *
 * A declared column that doesn't exist on the model resolves to HIDDEN rather
 * than silently exposing the table — a typo fails closed, not open.
 *
 * Modes returned by resolve():
 *   ['mode' => 'all']                          ownerless — read every row
 *   ['mode' => 'owner', 'columns' => [..]]     scope WHERE col = me (OR-match)
 *   ['mode' => 'polymorphic_owner', 'type_column' => .., 'id_column' => ..,
 *    'type_value' => '..']                     scope WHERE type_column = type_value AND id_column = me
 *   ['mode' => 'hidden', 'reason' => '..']     not exposed to members
 */
class OwnerScopeResolver {

    /** Distinguishes "declared false" from "never declared". */
    private const UNSET = "\0__ai_owner_field_unset__\0";

    /** A column name ending in one of these is an owner-id column. */
    private const OWNER_SUFFIXES = ['_usr_user_id', '_owner_user_id'];

    /**
     * Resolve the member-read scope for a model class. Pure; safe to call at
     * scan time (only reads static class properties).
     */
    public static function resolve(string $class): array {
        $fields = isset($class::$field_specifications) && is_array($class::$field_specifications)
            ? array_keys($class::$field_specifications)
            : [];

        $decl = self::declaredOwnerField($class);

        // Undeclared — infer from the column names (checked first; the UNSET
        // sentinel is itself a string and must not fall into the string branch).
        if ($decl === self::UNSET) {
            $candidates = array_values(array_filter($fields, [self::class, 'isOwnerColumn']));
            if (count($candidates) === 1) {
                return ['mode' => 'owner', 'columns' => [$candidates[0]]];
            }
            if (count($candidates) === 0) {
                return ['mode' => 'hidden', 'reason' => 'no owner column inferred; stays hidden (declare $ai_owner_field = a column to scope, or false if it is ownerless catalog)'];
            }
            return [
                'mode'   => 'hidden',
                'reason' => 'ambiguous ownership — multiple owner columns: ' . implode(', ', $candidates),
            ];
        }

        // Ownerless catalog/config.
        if ($decl === false) {
            return ['mode' => 'all'];
        }

        // Explicit single column.
        if (is_string($decl) && $decl !== '') {
            return in_array($decl, $fields, true)
                ? ['mode' => 'owner', 'columns' => [$decl]]
                : ['mode' => 'hidden', 'reason' => "declared owner column '$decl' is not a field on the model"];
        }

        // Polymorphic owner: a (type_column, id_column) pair instead of a single
        // FK-style column (e.g. CalendarEntry/Schedule's CalendarSubject shape).
        // Checked before the OR-match array branch below, which would otherwise
        // filter this nested array down to an empty column list and fail closed
        // with a misleading "empty list" reason instead of the real one.
        if (is_array($decl) && isset($decl['polymorphic'])) {
            $poly = $decl['polymorphic'];
            $type_column = is_array($poly) ? ($poly['type_column'] ?? null) : null;
            $id_column   = is_array($poly) ? ($poly['id_column']   ?? null) : null;
            $type_value  = is_array($poly) ? ($poly['type_value']  ?? null) : null;

            if (!is_string($type_column) || $type_column === ''
                || !is_string($id_column) || $id_column === ''
                || !is_string($type_value) || $type_value === '') {
                return ['mode' => 'hidden', 'reason' => '$ai_owner_field polymorphic declaration is missing type_column, id_column, or type_value'];
            }
            if (!in_array($type_column, $fields, true) || !in_array($id_column, $fields, true)) {
                return ['mode' => 'hidden', 'reason' => "declared polymorphic column(s) not on the model: $type_column, $id_column"];
            }
            return [
                'mode'        => 'polymorphic_owner',
                'type_column' => $type_column,
                'id_column'   => $id_column,
                'type_value'  => $type_value,
            ];
        }

        // Explicit OR-match list.
        if (is_array($decl) && !empty($decl)) {
            $cols = array_values(array_filter($decl, fn($c) => is_string($c) && $c !== ''));
            $missing = array_values(array_diff($cols, $fields));
            if (empty($cols) || !empty($missing)) {
                $bad = empty($cols) ? '(empty list)' : implode(', ', $missing);
                return ['mode' => 'hidden', 'reason' => "declared owner column(s) not on the model: $bad"];
            }
            return ['mode' => 'owner', 'columns' => $cols];
        }

        // A declared-but-malformed value (empty string, empty array, wrong type)
        // is a misconfiguration; fail closed rather than guess.
        return ['mode' => 'hidden', 'reason' => '$ai_owner_field is set to an unusable value'];
    }

    private static function isOwnerColumn(string $field): bool {
        foreach (self::OWNER_SUFFIXES as $suffix) {
            if (substr($field, -strlen($suffix)) === $suffix) return true;
        }
        return false;
    }

    /**
     * Read $ai_owner_field as declared, or the UNSET sentinel if the class never
     * declares it. Only a public static property counts (mirrors ModelRegistry).
     */
    private static function declaredOwnerField(string $class) {
        $rc = new ReflectionClass($class);
        if (!$rc->hasProperty('ai_owner_field')) return self::UNSET;
        $prop = $rc->getProperty('ai_owner_field');
        if (!$prop->isStatic() || !$prop->isPublic()) return self::UNSET;
        return $class::$ai_owner_field;
    }

}
