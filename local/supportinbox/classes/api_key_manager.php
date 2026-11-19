<?php
namespace local_supportinbox;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates, verifies and revokes the scoped bearer keys used by external
 * callers (the Application Nexus) against /support/v1.
 *
 * The raw key is only ever shown once, at creation time, in the admin UI.
 * Only its sha256 hash is stored, so a database leak alone can't be used
 * to call the API.
 */
class api_key_manager {

    /** Prefix on every raw key, so a leaked key is recognisable at a glance. */
    const KEY_PREFIX = 'nexus_sk_';

    /**
     * Create a new key and return the ONE-TIME raw value.
     *
     * @param string $label e.g. "Application Nexus - production"
     * @param string $environment 'staging' or 'production'
     * @param int $createdby Moodle user id
     * @param string $ipallowlist comma-separated IPs, or '' to not enforce
     * @return array ['id' => int, 'rawkey' => string]
     */
    public static function create(string $label, string $environment, int $createdby, string $ipallowlist = ''): array {
        global $DB;

        if (!in_array($environment, ['staging', 'production'], true)) {
            throw new \invalid_parameter_exception('environment must be staging or production');
        }

        // 32 random bytes -> 64 hex chars of entropy, plus a readable prefix.
        $raw = self::KEY_PREFIX . bin2hex(random_bytes(32));

        $record = new \stdClass();
        $record->label        = $label;
        $record->environment  = $environment;
        $record->keyhash      = hash('sha256', $raw);
        $record->keyprefix    = substr($raw, 0, 16);
        $record->scope        = 'support';
        $record->ipallowlist  = $ipallowlist;
        $record->revoked      = 0;
        $record->createdby    = $createdby;
        $record->timecreated  = time();
        $record->timerevoked  = null;
        $record->lastusedtime = null;

        $id = $DB->insert_record('local_supportinbox_apikeys', $record);

        return ['id' => $id, 'rawkey' => $raw];
    }

    /**
     * Look up and validate a raw bearer key from an incoming request.
     *
     * @param string $rawkey
     * @param string|null $callerip Used only if the key has an IP allowlist set.
     * @return \stdClass|null The apikeys record, or null if invalid/revoked/IP-blocked.
     */
    public static function verify(string $rawkey, ?string $callerip = null): ?\stdClass {
        global $DB;

        if ($rawkey === '') {
            return null;
        }

        $hash = hash('sha256', $rawkey);
        $record = $DB->get_record('local_supportinbox_apikeys', ['keyhash' => $hash]);

        if (!$record || $record->revoked) {
            return null;
        }

        if (!empty($record->ipallowlist) && $callerip !== null) {
            $allowed = array_map('trim', explode(',', $record->ipallowlist));
            if (!in_array($callerip, $allowed, true)) {
                return null;
            }
        }

        $DB->set_field('local_supportinbox_apikeys', 'lastusedtime', time(), ['id' => $record->id]);

        return $record;
    }

    /**
     * Revoke a key. Revocation is immediate and permanent; issue a new key
     * to replace it (this is the "rotate" half of rotation).
     */
    public static function revoke(int $id): void {
        global $DB;
        $DB->set_field('local_supportinbox_apikeys', 'revoked', 1, ['id' => $id]);
        $DB->set_field('local_supportinbox_apikeys', 'timerevoked', time(), ['id' => $id]);
    }

    /**
     * All keys, newest first, for the admin listing. Never includes the raw key.
     */
    public static function list_all(): array {
        global $DB;
        return $DB->get_records('local_supportinbox_apikeys', null, 'timecreated DESC');
    }
}