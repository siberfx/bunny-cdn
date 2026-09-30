<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

enum DnsRecordType: int
{
    case A = 0;
    case AAAA = 1;
    case CNAME = 2;
    case TXT = 3;
    case MX = 4;
    case Redirect = 5;
    case Flatten = 6;
    case PullZone = 7;
    case SRV = 8;
    case CAA = 9;
    case PTR = 10;
    case Script = 11;
    case NS = 12;
    case SVCB = 13;
    case HTTPS = 14;
    case TLSA = 15;
}
