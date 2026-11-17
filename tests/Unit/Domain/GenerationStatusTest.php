<?php

use App\Domain\Generation\GenerationStatus;

/** from => the only statuses it may move to. */
function allowedTransitions(): array
{
    return [
        'pending' => ['queued', 'processing', 'succeeded', 'failed'],
        'queued' => ['processing', 'succeeded', 'failed'],
        'processing' => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed' => [],
    ];
}

it('allows exactly the documented transitions and nothing else', function () {
    foreach (GenerationStatus::cases() as $from) {
        foreach (GenerationStatus::cases() as $to) {
            $expected = in_array($to->value, allowedTransitions()[$from->value], true);

            expect($from->canTransitionTo($to))
                ->toBe($expected, "{$from->value} -> {$to->value} should be ".($expected ? 'allowed' : 'rejected'));
        }
    }
});

it('never moves backwards', function () {
    expect(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Queued))->toBeFalse()
        ->and(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Pending))->toBeFalse()
        ->and(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Pending))->toBeFalse();
});

it('treats a repeat of the current status as not a transition', function () {
    foreach (GenerationStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

it('lets a late processing event never undo a final state', function () {
    expect(GenerationStatus::Succeeded->canTransitionTo(GenerationStatus::Processing))->toBeFalse()
        ->and(GenerationStatus::Failed->canTransitionTo(GenerationStatus::Processing))->toBeFalse();
});

it('never converts one final state into the other', function () {
    expect(GenerationStatus::Succeeded->canTransitionTo(GenerationStatus::Failed))->toBeFalse()
        ->and(GenerationStatus::Failed->canTransitionTo(GenerationStatus::Succeeded))->toBeFalse();
});

it('allows skipping ahead, since webhooks can arrive early or out of order', function () {
    expect(GenerationStatus::Pending->canTransitionTo(GenerationStatus::Succeeded))->toBeTrue()
        ->and(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Succeeded))->toBeTrue()
        ->and(GenerationStatus::Pending->canTransitionTo(GenerationStatus::Failed))->toBeTrue();
});

it('knows which statuses are final', function () {
    expect(GenerationStatus::Succeeded->isTerminal())->toBeTrue()
        ->and(GenerationStatus::Failed->isTerminal())->toBeTrue()
        ->and(GenerationStatus::Pending->isTerminal())->toBeFalse()
        ->and(GenerationStatus::Queued->isTerminal())->toBeFalse()
        ->and(GenerationStatus::Processing->isTerminal())->toBeFalse();
});

it('is backed by the strings stored in the database and sent by Pixelforge', function () {
    expect(GenerationStatus::from('queued'))->toBe(GenerationStatus::Queued)
        ->and(GenerationStatus::tryFrom('succeeded'))->toBe(GenerationStatus::Succeeded)
        ->and(GenerationStatus::tryFrom('bogus'))->toBeNull();
});
