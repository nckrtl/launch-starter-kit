<?php

declare(strict_types=1);

use App\Delivery\Workflow\OrbitPlanningResolutionCatalog;

it('loads the exact checked-in ORB-71 planning correction', function () {
    $catalog = app(OrbitPlanningResolutionCatalog::class);
    $correction = $catalog->find(
        '6c72f49f-55f7-4cb9-b47a-41fa9d9473cf',
        'ORB-71',
        '43640aaf5de5c119a3b1651c03c9fea4638b4aa002cf74596e9714373cdda4e4',
        '45d634da29199f29881ee2511c7803f9fde3e296ef9bb66c35530b9664e19a2e',
    );

    expect($catalog->has('ORB-71'))->toBeTrue()
        ->and($correction)->not->toBeNull()
        ->and($correction?->correctedContractHash)
        ->toBe('a1aaca1eed1efead10af4e6fb561f0aa2fda98fd42d33581d31679f42ac5657b')
        ->and($correction?->correctedDescriptionHash)
        ->toBe('0a167f2d51d776771a8a6622767db17bef9422a26fd814f12192bf43920c222f')
        ->and($correction?->correctedDescription)
        ->toBe(rtrim(file_get_contents(
            resource_path('delivery/orbit/planning-resolutions/orb-71.md'),
        ), "\r\n"));
});

it('rejects identity, receipt, contract, file, and schema drift', function (string $case) {
    $entry = config('commander.delivery.orbit_planning_resolutions.ORB-71');
    expect($entry)->toBeArray();

    $issueId = '6c72f49f-55f7-4cb9-b47a-41fa9d9473cf';
    $receiptHash = '43640aaf5de5c119a3b1651c03c9fea4638b4aa002cf74596e9714373cdda4e4';
    $contractHash = '45d634da29199f29881ee2511c7803f9fde3e296ef9bb66c35530b9664e19a2e';

    match ($case) {
        'issue identity' => $issueId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'receipt' => $receiptHash = str_repeat('a', 64),
        'current contract' => $contractHash = str_repeat('b', 64),
        'corrected contract' => $entry['corrected_contract_sha256'] = 'not-a-hash',
        'description hash' => $entry['corrected_description_sha256'] = str_repeat('d', 64),
        'description path' => $entry['corrected_description'] = base_path('README.md'),
        'schema' => $entry['unexpected'] = true,
    };

    config()->set('commander.delivery.orbit_planning_resolutions.ORB-71', $entry);

    expect(app(OrbitPlanningResolutionCatalog::class)->find(
        $issueId,
        'ORB-71',
        $receiptHash,
        $contractHash,
    ))->toBeNull();
})->with([
    'issue identity',
    'receipt',
    'current contract',
    'corrected contract',
    'description hash',
    'description path',
    'schema',
]);
