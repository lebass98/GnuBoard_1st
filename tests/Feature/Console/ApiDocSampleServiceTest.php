<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Support\ApiDoc\ApiDocSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API 문서 실측용 완전 샘플 시더 Feature 테스트.
 *
 * 도메인별 완전 샘플이 생성되고, 재실행 시 중복 없이 멱등하며,
 * 완전 사용자 샘플의 프로필 필드가 채워지는지 검증한다.
 */
class ApiDocSampleServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 도메인별_대표_샘플_맵을_반환한다(): void
    {
        $map = (new ApiDocSampleService)->seed();

        $this->assertArrayHasKey('users', $map);
        $this->assertArrayHasKey('roles', $map);
        $this->assertArrayHasKey('menus', $map);
        $this->assertArrayHasKey('schedules', $map);

        // 각 항목은 model/key/value 구조
        $this->assertSame(User::class, $map['users']['model']);
        $this->assertArrayHasKey('key', $map['users']);
        $this->assertNotEmpty($map['users']['value']);
    }

    #[Test]
    public function 완전_사용자_샘플은_프로필_필드가_채워진다(): void
    {
        (new ApiDocSampleService)->seed();

        $user = User::where('email', 'apidoc-sample-user@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->nickname);
        $this->assertNotNull($user->mobile);
        $this->assertSame('KR', $user->country);
        $this->assertSame('ko', $user->language);
        $this->assertNotNull($user->bio);
        $this->assertGreaterThan(0, $user->roles()->count());
    }

    #[Test]
    public function 재실행_시_샘플이_중복_생성되지_않는다(): void
    {
        $service = new ApiDocSampleService;

        $service->seed();
        $countAfterFirst = User::where('email', 'apidoc-sample-user@example.com')->count();

        $service->seed();
        $countAfterSecond = User::where('email', 'apidoc-sample-user@example.com')->count();

        $this->assertSame(1, $countAfterFirst);
        $this->assertSame(1, $countAfterSecond);
    }
}
