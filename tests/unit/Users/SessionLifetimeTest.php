<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Users;

use MichaelDanielczok\Piklis\Users\SessionLifetime;
use PHPUnit\Framework\TestCase;

class SessionLifetimeTest extends TestCase
{
    public function testCoreDefaultIsTooShort(): void
    {
        $this->assertTrue(SessionLifetime::isConfiguredTooShort(0));
        $this->assertTrue(SessionLifetime::isConfiguredTooShort(1400));
    }

    public function testNinetyDaysAndLongerAreKept(): void
    {
        $this->assertFalse(SessionLifetime::isConfiguredTooShort(SessionLifetime::SECONDS));
        $this->assertFalse(SessionLifetime::isConfiguredTooShort(SessionLifetime::SECONDS + 1));
    }

    public function testCookieIsRenewedWhenNeverRenewed(): void
    {
        $this->assertTrue(SessionLifetime::isCookieRenewalDue(null, 1000));
    }

    public function testCookieIsNotRenewedWithinOneDay(): void
    {
        $renewedAt = 1000;
        $now = $renewedAt + SessionLifetime::COOKIE_RENEWAL_INTERVAL - 1;

        $this->assertFalse(SessionLifetime::isCookieRenewalDue($renewedAt, $now));
    }

    public function testCookieIsRenewedAfterOneDay(): void
    {
        $renewedAt = 1000;
        $now = $renewedAt + SessionLifetime::COOKIE_RENEWAL_INTERVAL;

        $this->assertTrue(SessionLifetime::isCookieRenewalDue($renewedAt, $now));
    }
}
