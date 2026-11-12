<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Policies\DocumentPolicy;
use PHPUnit\Framework\TestCase;

final class DocumentPolicyTest extends TestCase
{
    private const REG = 1;
    private const BUR = 2;
    private const ICT = 3;

    private DocumentPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DocumentPolicy();
    }

    public function testViewIsLimitedToDepartmentsOnTheRoute(): void
    {
        $doc = $this->doc(['origin_department_id' => self::REG, 'current_department_id' => self::BUR]);

        $this->assertTrue($this->policy->canView($this->user(self::REG), $doc, []), 'origin');
        $this->assertTrue($this->policy->canView($this->user(self::BUR), $doc, []), 'current holder');
        $this->assertTrue($this->policy->canView($this->user(self::ICT), $doc, [self::REG, self::ICT]), 'passed through');
        $this->assertFalse($this->policy->canView($this->user(self::ICT), $doc, [self::REG, self::BUR]), 'unrelated');
        $this->assertTrue($this->policy->canView($this->user(self::ICT, 'admin'), $doc, []), 'admin sees all');
    }

    public function testConfidentialDocumentsFollowTheSameRouteRule(): void
    {
        $doc = $this->doc(['confidentiality' => 'confidential']);

        $this->assertTrue($this->policy->canView($this->user(self::REG), $doc, []));
        $this->assertFalse($this->policy->canView($this->user(self::ICT, 'hod'), $doc, []));
    }

    public function testInactiveUsersCanDoNothing(): void
    {
        $user = $this->user(self::REG, 'admin', ['is_active' => 0]);
        $doc = $this->doc();

        $this->assertFalse($this->policy->canView($user, $doc, []));
        $this->assertFalse($this->policy->canEdit($user, $doc));
        $this->assertFalse($this->policy->canCancel($user, $doc));
    }

    public function testEditAndSendRequireHolderDepartmentAndHeldStatus(): void
    {
        $draft = $this->doc();
        $received = $this->doc(['status' => 'received']);

        $this->assertTrue($this->policy->canEdit($this->user(self::REG), $draft));
        $this->assertTrue($this->policy->canSend($this->user(self::REG), $received, null));
        $this->assertFalse($this->policy->canSend($this->user(self::BUR), $draft, null), 'not holder');
        $this->assertFalse($this->policy->canSend($this->user(self::BUR, 'admin'), $draft, null), 'admin outside holder dept');
        $this->assertFalse($this->policy->canSend($this->user(self::REG), $received, $this->movement()), 'already in transit');

        foreach (['in_transit', 'completed', 'cancelled'] as $status) {
            $this->assertFalse($this->policy->canEdit($this->user(self::REG), $this->doc(['status' => $status])), $status);
        }
    }

    public function testOnlyTheDestinationDepartmentCanReceive(): void
    {
        $doc = $this->doc(['status' => 'in_transit']);
        $movement = $this->movement(['to_department_id' => self::BUR]);

        $this->assertTrue($this->policy->canReceive($this->user(self::BUR), $doc, $movement));
        $this->assertFalse($this->policy->canReceive($this->user(self::ICT), $doc, $movement));
        $this->assertFalse($this->policy->canReceive($this->user(self::ICT, 'admin'), $doc, $movement));
        $this->assertFalse($this->policy->canReceive($this->user(self::BUR), $doc, null));
        $this->assertFalse($this->policy->canReceive($this->user(self::BUR), $doc, $this->movement(['status' => 'received'])));
        $this->assertFalse($this->policy->canReceive($this->user(self::BUR), $this->doc(['status' => 'received']), $movement));
    }

    public function testReturnNeedsAReceivedMovementIntoTheHolder(): void
    {
        $doc = $this->doc(['status' => 'received', 'current_department_id' => self::BUR]);
        $came = $this->movement(['from_department_id' => self::REG, 'to_department_id' => self::BUR, 'status' => 'received']);

        $this->assertTrue($this->policy->canReturn($this->user(self::BUR), $doc, $came));
        $this->assertFalse($this->policy->canReturn($this->user(self::BUR), $doc, null));
        $this->assertFalse($this->policy->canReturn($this->user(self::REG), $doc, $came));
    }

    public function testRecallBySenderHodOfSendingDeptOrAdmin(): void
    {
        $doc = $this->doc(['status' => 'in_transit']);
        $movement = $this->movement(['from_user_id' => 10, 'from_department_id' => self::REG]);

        $this->assertTrue($this->policy->canRecall($this->user(self::REG, 'staff', ['id' => 10]), $doc, $movement), 'sender');
        $this->assertFalse($this->policy->canRecall($this->user(self::REG, 'staff', ['id' => 11]), $doc, $movement), 'colleague');
        $this->assertTrue($this->policy->canRecall($this->user(self::REG, 'hod', ['id' => 12]), $doc, $movement), 'HOD of sending dept');
        $this->assertFalse($this->policy->canRecall($this->user(self::BUR, 'hod', ['id' => 13]), $doc, $movement), 'other HOD');
        $this->assertTrue($this->policy->canRecall($this->user(self::ICT, 'admin', ['id' => 14]), $doc, $movement), 'admin');
        $this->assertFalse(
            $this->policy->canRecall($this->user(self::REG, 'staff', ['id' => 10]), $doc, $this->movement(['status' => 'received'])),
            'already received'
        );
    }

    public function testCompleteOnlyWhenReceivedByHolder(): void
    {
        $this->assertTrue($this->policy->canComplete($this->user(self::REG), $this->doc(['status' => 'received'])));
        $this->assertFalse($this->policy->canComplete($this->user(self::REG), $this->doc(['status' => 'draft'])));
        $this->assertFalse($this->policy->canComplete($this->user(self::BUR), $this->doc(['status' => 'received'])));
    }

    public function testCancelOnlyDraftsByCreatorOrAdmin(): void
    {
        $draft = $this->doc(['created_by' => 10]);

        $this->assertTrue($this->policy->canCancel($this->user(self::REG, 'staff', ['id' => 10]), $draft), 'creator');
        $this->assertFalse($this->policy->canCancel($this->user(self::REG, 'hod', ['id' => 11]), $draft), 'colleague HOD');
        $this->assertTrue($this->policy->canCancel($this->user(self::BUR, 'admin', ['id' => 12]), $draft), 'admin');
        $this->assertFalse($this->policy->canCancel($this->user(self::REG, 'staff', ['id' => 10]), $this->doc(['status' => 'in_transit', 'created_by' => 10])));
    }

    public function testOnlyActiveAdminsManageHiddenAttachments(): void
    {
        $this->assertTrue($this->policy->canManageHiddenAttachments($this->user(self::ICT, 'admin')));
        $this->assertFalse($this->policy->canManageHiddenAttachments($this->user(self::ICT, 'hod')));
        $this->assertFalse($this->policy->canManageHiddenAttachments($this->user(self::ICT, 'staff')));
        $this->assertFalse($this->policy->canManageHiddenAttachments($this->user(self::ICT, 'admin', ['is_active' => 0])));
    }

    public function testReceiveRejectsAMovementBelongingToAnotherDocument(): void
    {
        $doc = $this->doc(['status' => 'in_transit', 'id' => 1]);

        $this->assertFalse($this->policy->canReceive($this->user(self::BUR), $doc, $this->movement(['document_id' => 2])));
    }

    public function testInactiveUsersCannotActOnMovements(): void
    {
        $inactive = ['is_active' => 0];
        $inTransit = $this->doc(['status' => 'in_transit']);
        $received = $this->doc(['status' => 'received']);

        $this->assertFalse($this->policy->canReceive($this->user(self::BUR, 'staff', $inactive), $inTransit, $this->movement()));
        $this->assertFalse($this->policy->canRecall($this->user(self::REG, 'admin', $inactive), $inTransit, $this->movement()));
        $this->assertFalse($this->policy->canComplete($this->user(self::REG, 'staff', $inactive), $received));
        $this->assertFalse($this->policy->canSend($this->user(self::REG, 'staff', $inactive), $received, null));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function user(int $department, string $role = 'staff', array $extra = []): array
    {
        return $extra + ['id' => 100 + $department, 'department_id' => $department, 'role' => $role, 'is_active' => 1];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function doc(array $extra = []): array
    {
        return $extra + [
            'id' => 1, 'status' => 'draft', 'confidentiality' => 'normal', 'created_by' => 101,
            'origin_department_id' => self::REG, 'current_department_id' => self::REG,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function movement(array $extra = []): array
    {
        return $extra + [
            'document_id' => 1, 'status' => 'in_transit', 'from_user_id' => 101,
            'from_department_id' => self::REG, 'to_department_id' => self::BUR,
        ];
    }
}
