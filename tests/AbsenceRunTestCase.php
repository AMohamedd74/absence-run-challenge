<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Decision;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Repository\DecisionRepository;
use App\Repository\LeaveBalanceRepository;
use App\Repository\LeaveRequestRepository;
use App\Leave\Policy\BalanceCalculator;
use App\Leave\AbsenceRun\DecisionRecorder;
use App\Leave\Policy\EntitlementCalculator;
use App\Leave\Decision\EvaluatorRegistry;
use App\Leave\Decision\SickEvaluator;
use App\Leave\Decision\SpecialEvaluator;
use App\Leave\Decision\UnpaidEvaluator;
use App\Leave\Decision\VacationEvaluator;
use App\Leave\Policy\HolidayCalendar;
use App\Leave\AbsenceRun\LeaveRequestProcessor;
use App\Leave\Decision\LeaveRequestValidator;
use App\Leave\Policy\OverlapChecker;
use App\Leave\Policy\WorkingDayCounter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for processor tests: boots the kernel, gives each test a fresh
 * SQLite schema, and wires the processor against an in-memory HR API client.
 */
abstract class AbsenceRunTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected FakeHrApiClient $hrApi;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->hrApi = new FakeHrApiClient();
    }

    protected function processor(): LeaveRequestProcessor
    {
        $requests = $this->em->getRepository(LeaveRequest::class);
        $balances = $this->em->getRepository(LeaveBalance::class);
        $decisions = $this->em->getRepository(Decision::class);
        \assert($requests instanceof LeaveRequestRepository);
        \assert($balances instanceof LeaveBalanceRepository);
        \assert($decisions instanceof DecisionRepository);

        $workingDays = new WorkingDayCounter(new HolidayCalendar());
        $balanceCalculator = new BalanceCalculator($balances, new EntitlementCalculator());
        $overlaps = new OverlapChecker($requests, $workingDays);

        $registry = new EvaluatorRegistry(
            new VacationEvaluator($overlaps, $balanceCalculator, $workingDays),
            new SickEvaluator($overlaps),
            new UnpaidEvaluator(),
            new SpecialEvaluator(),
        );
        $recorder = new DecisionRecorder($this->em, $decisions, $this->hrApi, $balanceCalculator);

        return new LeaveRequestProcessor(
            $requests,
            $decisions,
            new LeaveRequestValidator(),
            $registry,
            $recorder,
            new NullLogger(),
        );
    }

    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }
}
