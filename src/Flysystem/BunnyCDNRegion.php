<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Flysystem;

/**
 * Edge Storage regions (the primary region of a storage zone).
 */
final class BunnyCDNRegion
{
    public const string FALKENSTEIN = 'de';

    public const string STOCKHOLM = 'se';

    public const string NEW_YORK = 'ny';

    public const string LOS_ANGELES = 'la';

    public const string SINGAPORE = 'sg';

    public const string SYDNEY = 'syd';

    public const string UNITED_KINGDOM = 'uk';

    public const string BRAZIL = 'br';

    public const string JOHANNESBURG = 'jh';

    public const string DEFAULT = self::FALKENSTEIN;

    #[\Deprecated('use BunnyCDNRegion::LOS_ANGELES instead', since: '1.1.0')]
    public const string LOS_ANGELAS = 'la';
}
