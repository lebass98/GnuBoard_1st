<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 include 파일의 로드 순서 독립성 회귀 가드 (이슈 #465 후속).
 *
 * 회귀: `install-process.php` 가 `installer-runtime.php` 를 `functions.php` 보다
 * *먼저* require 하도록 바뀌면서 다음 fatal 이 발생했다.
 *
 *   Fatal error: Cannot redeclare escapeEnvValue()
 *   (previously declared in installer-runtime.php) in functions.php
 *
 * 원인: `installer-runtime.php` 는 `escapeEnvValue()` 를 `function_exists` 가드와
 * 함께 polyfill 로 선언하는데, `functions.php` 는 같은 함수를 **가드 없이 무조건**
 * 선언한다. 따라서 runtime 이 먼저 로드되면 functions 로드 시 재선언 fatal.
 *
 * 증상: PHP fatal 이라 JSON 이 출력되지 않음 → 빈 응답 본문 →
 *       브라우저의 `res.json()` 이 "Unexpected end of JSON input" 으로 실패
 *       → 설치 시작 즉시 "설치 실패" (SSE/폴링 양쪽 동일 — 두 모드 모두
 *          install-process.php 를 경유하므로)
 *
 * 본 테스트가 고정하는 계약:
 *   인스톨러 include 파일들은 **어떤 순서로 로드되어도** 함수 재선언 fatal 을
 *   일으키지 않는다. 각 엔드포인트가 require 순서를 바꿔도 안전해야 한다.
 *
 * 별도 프로세스로 실행하는 이유: 함수 선언은 프로세스 전역이라 PHPUnit 프로세스
 * 안에서 두 파일을 로드하면 다른 Installer 테스트를 오염시킨다. 실제 fatal 여부를
 * 관측하려면 격리된 PHP 프로세스에서 로드해야 한다.
 */
class InstallerIncludeOrderTest extends TestCase
{
    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);
    }

    /**
     * 인스톨러 include 파일 2개를 지정한 순서로 로드하는 격리 PHP 프로세스 실행.
     *
     * @param  array<int, string>  $relativeIncludes  BASE_PATH 기준 상대 경로 목록
     * @return array{exitCode:int, output:string}
     */
    private function loadInIsolatedProcess(array $relativeIncludes): array
    {
        $root = str_replace('\\', '/', $this->projectRoot);

        $lines = ['error_reporting(E_ALL);', "define('BASE_PATH', '{$root}');"];
        foreach ($relativeIncludes as $include) {
            $lines[] = "require_once '{$root}/{$include}';";
        }
        $lines[] = "echo 'LOAD_OK';";

        $code = implode("\n", $lines);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes);

        $this->assertIsResource($process, 'PHP 하위 프로세스를 띄우지 못함');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exitCode' => $exitCode, 'output' => $stdout.$stderr];
    }

    /**
     * 회귀 재현 — installer-runtime.php 를 functions.php 보다 먼저 로드.
     *
     * 이 순서가 곧 install-process.php 의 require 순서였고, escapeEnvValue()
     * 재선언 fatal 로 빈 응답이 발생했다.
     */
    public function test_runtime_before_functions_does_not_fatal(): void
    {
        $result = $this->loadInIsolatedProcess([
            'public/install/includes/installer-runtime.php',
            'public/install/includes/functions.php',
        ]);

        $this->assertStringNotContainsString(
            'Cannot redeclare',
            $result['output'],
            'installer-runtime.php 를 functions.php 보다 먼저 로드하면 함수 재선언 fatal 이 발생한다. '
            .'중복 선언되는 함수에 function_exists 가드가 필요하다.',
        );
        $this->assertStringContainsString('LOAD_OK', $result['output'], '두 파일 로드가 완료되어야 함');
        $this->assertSame(0, $result['exitCode'], 'fatal 없이 정상 종료해야 함');
    }

    /**
     * 기존 순서(functions.php 먼저) 도 계속 동작해야 한다 — 회귀 방지.
     *
     * finalize-env.php / task-runner.php 가 이 순서를 사용한다.
     */
    public function test_functions_before_runtime_does_not_fatal(): void
    {
        $result = $this->loadInIsolatedProcess([
            'public/install/includes/functions.php',
            'public/install/includes/installer-runtime.php',
        ]);

        $this->assertStringNotContainsString('Cannot redeclare', $result['output']);
        $this->assertStringContainsString('LOAD_OK', $result['output']);
        $this->assertSame(0, $result['exitCode']);
    }

    /**
     * install-process.php 의 실제 require 순서를 그대로 재현.
     *
     * 이 엔드포인트가 SSE / 폴링 양 모드의 공통 시작점이므로, 여기서 fatal 이 나면
     * 두 모드 모두 설치 시작 즉시 실패한다.
     */
    public function test_install_process_require_chain_does_not_fatal(): void
    {
        $result = $this->loadInIsolatedProcess([
            'public/install/includes/config.php',
            'public/install/includes/installer-state.php',
            'public/install/includes/installer-runtime.php',
            'public/install/includes/functions.php',
        ]);

        $this->assertStringNotContainsString(
            'Cannot redeclare',
            $result['output'],
            'install-process.php 의 require 체인에서 재선언 fatal 발생 — '
            .'설치 시작 API 가 빈 응답을 반환하여 "Unexpected end of JSON input" 이 된다',
        );
        $this->assertSame(0, $result['exitCode']);
    }

    /**
     * installer-runtime.php 가 polyfill 로 선언하는 함수는 functions.php 에서도
     * function_exists 가드를 가져야 한다 — 정적 계약 검사.
     *
     * 앞으로 두 파일에 중복 함수가 새로 추가되어도 가드 없이는 통과하지 못한다.
     */
    public function test_functions_shared_with_runtime_are_all_guarded(): void
    {
        $functionsPath = $this->projectRoot.'/public/install/includes/functions.php';
        $runtimePath = $this->projectRoot.'/public/install/includes/installer-runtime.php';

        $functionsSource = (string) file_get_contents($functionsPath);
        $runtimeSource = (string) file_get_contents($runtimePath);

        // installer-runtime.php 가 선언하는 함수 목록 추출
        preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $runtimeSource, $m);
        $runtimeFunctions = array_unique($m[1]);

        $this->assertNotEmpty($runtimeFunctions, 'installer-runtime.php 에서 함수를 하나도 못 찾음 — 추출 정규식 점검 필요');

        foreach ($runtimeFunctions as $fn) {
            // functions.php 에도 같은 함수가 선언되어 있는가?
            if (! preg_match('/^\s*function\s+'.preg_quote($fn, '/').'\s*\(/m', $functionsSource)) {
                continue; // 중복 아님 — 안전
            }

            // 중복이라면 functions.php 쪽에 function_exists 가드가 있어야 한다
            $this->assertMatchesRegularExpression(
                "/function_exists\(\s*['\"]".preg_quote($fn, '/')."['\"]\s*\)/",
                $functionsSource,
                "functions.php 의 {$fn}() 이 installer-runtime.php 와 중복 선언되는데 "
                ."function_exists 가드가 없다. 로드 순서에 따라 'Cannot redeclare' fatal 이 발생한다.",
            );
        }
    }
}
