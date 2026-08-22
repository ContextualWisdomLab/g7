<?php

namespace Tests\Feature\Workflows;

use PHPUnit\Framework\TestCase;

/**
 * Guards the repository-local continuous integration security and quality contract.
 */
class ContinuousIntegrationWorkflowTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $workflowPath = dirname(__DIR__, 3).'/.github/workflows/ci.yml';
        $workflow = file_get_contents($workflowPath);

        self::assertNotFalse($workflow, 'The repository-local CI workflow must be readable.');
        $this->workflow = $workflow;
    }

    /**
     * Third-party actions must be immutable and checkout credentials ephemeral.
     */
    public function test_actions_are_pinned_to_commits_and_checkout_does_not_persist_credentials(): void
    {
        preg_match_all('/^\s*uses:\s*([^\s#]+)/m', $this->workflow, $matches);

        self::assertNotEmpty($matches[1], 'The CI workflow must invoke pinned actions.');
        foreach ($matches[1] as $actionReference) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[0-9a-f]{40}$/',
                $actionReference,
                "Action reference {$actionReference} is not pinned to an immutable commit.",
            );
        }

        self::assertSame(2, substr_count($this->workflow, 'persist-credentials: false'));
    }

    /**
     * Pull-request jobs must inspect the submitted commit, never GitHub's synthetic merge ref.
     */
    public function test_pull_request_jobs_checkout_the_exact_head_sha(): void
    {
        self::assertSame(
            2,
            substr_count($this->workflow, 'ref: ${{ github.event.pull_request.head.sha }}'),
            'Both CI jobs must explicitly checkout the pull-request head SHA.',
        );
    }

    /**
     * CI must remain read-only and must not use privileged agent credentials.
     */
    public function test_workflow_uses_least_privilege_and_contains_no_copilot_token(): void
    {
        self::assertMatchesRegularExpression('/^permissions:\n  contents: read$/m', $this->workflow);
        self::assertDoesNotMatchRegularExpression(
            '/^\s+(?:actions|checks|contents|deployments|id-token|issues|packages|pull-requests|security-events|statuses): write$/m',
            $this->workflow,
        );
        self::assertStringNotContainsString('pull_request_target:', $this->workflow);
        self::assertStringNotContainsString('COPILOT_GITHUB_TOKEN', $this->workflow);
    }

    /**
     * Temporary branch-writing repair workflows must not survive in a reviewable head.
     */
    public function test_temporary_branch_writing_repair_workflow_is_absent(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3).'/.github/workflows/pr1-repair.yml',
            'A reviewable head must not retain a workflow with branch-write authority.',
        );
    }

    /**
     * Superseded exact-head runs must be cancelled to protect runner capacity.
     */
    public function test_workflow_cancels_superseded_pull_request_runs(): void
    {
        self::assertStringContainsString('concurrency:', $this->workflow);
        self::assertStringContainsString(
            'group: ci-${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}',
            $this->workflow,
        );
        self::assertStringContainsString('cancel-in-progress: true', $this->workflow);
    }

    /**
     * PHP verification must cover supported runtime edges and production code.
     */
    public function test_php_job_enforces_locked_dependencies_quality_security_and_coverage(): void
    {
        self::assertStringContainsString("php-version: ['8.2', '8.5']", $this->workflow);
        self::assertStringContainsString('image: mysql:8.4.10', $this->workflow);
        self::assertStringContainsString('composer validate --strict --no-check-publish', $this->workflow);
        self::assertStringContainsString(
            'composer install --no-interaction --prefer-dist --no-progress',
            $this->workflow,
        );
        self::assertStringContainsString('composer audit --locked --no-interaction', $this->workflow);
        self::assertStringContainsString('vendor/bin/pint --test', $this->workflow);
        self::assertStringContainsString('php artisan migrate:fresh --env=testing --force', $this->workflow);
        self::assertStringContainsString('php artisan test --coverage --min=100', $this->workflow);
    }

    /**
     * Frontend verification must use locked dependencies on minimum and current LTS runtimes.
     */
    public function test_frontend_job_runs_security_tests_and_production_build(): void
    {
        self::assertStringContainsString('node-version: [22, 24]', $this->workflow);
        self::assertStringContainsString('run: npm ci', $this->workflow);
        self::assertStringContainsString('npm audit --omit=dev --audit-level=high', $this->workflow);
        self::assertStringContainsString('npm run test:run', $this->workflow);
        self::assertStringContainsString('npm run build', $this->workflow);
    }
}
