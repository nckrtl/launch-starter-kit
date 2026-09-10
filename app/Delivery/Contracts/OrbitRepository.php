<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;

interface OrbitRepository
{
    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation;

    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree;

    public function checkCandidate(OrbitProjectConfig $config, PreparedWorktree $worktree): CandidateCheck;

    public function verifyPlanningHandoff(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        CandidateCheck $candidate,
        PreparedIssueSnapshot $snapshot,
    ): VerifiedOrbitPlanningRepository;

    public function verifyPlanningArtifact(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        string $issueKey,
        string $artifactSha,
    ): VerifiedOrbitPlanningArtifact;

    public function writeIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        OrbitIssueSnapshot $snapshot,
    ): PreparedIssueSnapshot;

    public function verifyIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        PreparedIssueSnapshot $snapshot,
    ): void;
}
