<?php
/**
 * Nickname generator — "Adjective Noun" format.
 * Generates a unique nickname for new users at registration.
 */

const NICKNAME_ADJECTIVES = [
    'Angry','Brave','Calm','Dark','Epic','Fast','Gold','Happy','Iron','Jolly',
    'Kind','Lucky','Mad','Neon','Odd','Pink','Quick','Red','Sly','Tiny',
    'Ultra','Vast','Wild','Xtra','Young','Zany','Bold','Cool','Daring','Eager',
    'Fierce','Grim','Huge','Icy','Jade','Keen','Lazy','Mega','Noble','Outer',
    'Proud','Royal','Sharp','Tough','Uber','Vivid','Witty','Xenon','Yellow','Zero',
    'Acid','Blue','Crisp','Deep','Elite','Funky','Grand','Heavy','Ideal','Jumpy',
    'Kooky','Lush','Misty','Nimble','Optic','Plush','Quirky','Rusty','Sleek','Turbo',
    'Unreal','Vague','Wacky','Exact','Yummy','Zippy','Amber','Blaze','Cyber','Dusty',
    'Ember','Frosty','Gloom','Hyper','Inky','Jazzy','Kinky','Lunar','Murky','Nifty',
    'Onyx','Pixel','Quartz','Rainy','Sonic','Toxic','Umbra','Velvet','Wavy','Xenial',
];

const NICKNAME_NOUNS = [
    'Ace','Bear','Cat','Dog','Eagle','Fox','Ghost','Hawk','Imp','Jaguar',
    'King','Lion','Monk','Ninja','Owl','Panda','Queen','Raven','Snake','Tiger',
    'Unicorn','Viper','Wolf','Xenon','Yak','Zebra','Axe','Blade','Claw','Dart',
    'Edge','Fang','Gem','Horn','Icon','Jade','Knife','Lance','Mask','Node',
    'Orb','Pike','Quill','Rock','Shard','Thorn','Urn','Void','Wand','Xray',
    'Yarn','Zone','Atom','Bolt','Comet','Dusk','Echo','Flame','Glyph','Haze',
    'Isle','Jewel','Knot','Lore','Mist','Nova','Opal','Prism','Quest','Rift',
    'Storm','Tide','Umbra','Vale','Wave','Xenith','Yield','Zeal','Arc','Beam',
    'Core','Drift','Epoch','Flux','Grid','Helix','Iris','Jolt','Kite','Loop',
    'Maze','Nexus','Orbit','Pulse','Quasar','Realm','Spark','Trace','Unit','Vex',
];

/**
 * Generate a random "Adjective Noun" nickname.
 * Does NOT guarantee uniqueness — caller must check DB.
 */
function generateNickname(): string {
    $adj  = NICKNAME_ADJECTIVES[array_rand(NICKNAME_ADJECTIVES)];
    $noun = NICKNAME_NOUNS[array_rand(NICKNAME_NOUNS)];
    return $adj . ' ' . $noun;
}

/**
 * Generate a unique nickname, retrying up to $maxAttempts times.
 * On collision appends a random 2-digit suffix.
 * Returns the unique nickname string.
 * Throws RuntimeException if all attempts fail (astronomically unlikely).
 */
function generateUniqueNickname(PDO $pdo, int $maxAttempts = 10): string {
    $checkStmt = $pdo->prepare('SELECT 1 FROM users WHERE nickname = ? LIMIT 1');

    for ($i = 0; $i < $maxAttempts; $i++) {
        $nick = generateNickname();
        // After first 5 attempts, append a random 2-digit number to reduce collisions
        if ($i >= 5) {
            $nick .= ' ' . mt_rand(10, 99);
        }
        $checkStmt->execute([$nick]);
        if (!$checkStmt->fetch()) {
            return $nick;
        }
    }

    // Fallback: timestamp-based suffix guarantees uniqueness
    return generateNickname() . ' ' . substr((string)time(), -4);
}

/**
 * Validate a user-supplied nickname.
 * Returns null on success, or an error string on failure.
 */
function validateNickname(string $nick): ?string {
    $nick = trim($nick);
    if (strlen($nick) < 3)  return 'Nickname must be at least 3 characters.';
    if (strlen($nick) > 32) return 'Nickname must be at most 32 characters.';
    // Allow letters, digits, spaces, hyphens, underscores
    if (!preg_match('/^[\p{L}\p{N} _\-]+$/u', $nick)) {
        return 'Nickname may only contain letters, numbers, spaces, hyphens, and underscores.';
    }
    return null;
}
