<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $approver;

    protected User $staff;

    protected Product $product;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->approver = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        // Staff gudang boleh mengajukan tetapi tidak menyetujui.
        $this->staff = User::factory()->create([
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar',
            'unit' => 'pcs', 'min_stock' => 5,
        ]);

        $this->supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber Otoparts']);
    }

    /* --------------------------------------------------- alur pengajuan -- */

    public function test_staff_submission_waits_for_approval_and_does_not_move_stock(): void
    {
        $inbound = $this->makeInbound(10);

        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound))
            ->assertSessionHas('success');

        $inbound->refresh();

        $this->assertTrue($inbound->isPending());
        $this->assertSame($this->staff->id, $inbound->submitted_by);
        $this->assertSame(0, $this->product->refresh()->stock);
    }

    public function test_approver_submission_is_applied_immediately(): void
    {
        $inbound = $this->makeInbound(10);

        $this->actingAs($this->approver)->post(route('admin.inbounds.submit', $inbound))
            ->assertSessionHas('success');

        $inbound->refresh();

        $this->assertTrue($inbound->isPosted());
        $this->assertSame($this->approver->id, $inbound->approved_by);
        $this->assertSame(10, $this->product->refresh()->stock);
    }

    public function test_approving_a_pending_document_moves_the_stock(): void
    {
        $inbound = $this->makeInbound(7);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->approver)
            ->post(route('admin.approvals.approve', ['inbound', $inbound->id]))
            ->assertSessionHas('success');

        $inbound->refresh();

        $this->assertTrue($inbound->isPosted());
        $this->assertSame(7, $this->product->refresh()->stock);
    }

    public function test_rejecting_returns_the_document_with_a_reason(): void
    {
        $inbound = $this->makeInbound(7);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->approver)
            ->post(route('admin.approvals.reject', ['inbound', $inbound->id]), [
                'rejection_reason' => 'Jumlah tidak sesuai surat jalan.',
            ])->assertSessionHas('success');

        $inbound->refresh();

        $this->assertTrue($inbound->isRejected());
        $this->assertSame('Jumlah tidak sesuai surat jalan.', $inbound->rejection_reason);
        $this->assertSame(0, $this->product->refresh()->stock);
        $this->assertTrue($inbound->isEditable());
    }

    public function test_rejection_requires_a_reason(): void
    {
        $inbound = $this->makeInbound(7);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->approver)
            ->post(route('admin.approvals.reject', ['inbound', $inbound->id]), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertTrue($inbound->refresh()->isPending());
    }

    public function test_a_pending_document_can_not_be_edited_or_deleted(): void
    {
        $inbound = $this->makeInbound(7);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->approver)->get(route('admin.inbounds.edit', $inbound))
            ->assertRedirect(route('admin.inbounds.show', $inbound));

        $this->actingAs($this->approver)->delete(route('admin.inbounds.destroy', $inbound))
            ->assertSessionHas('error');
    }

    public function test_a_submission_can_be_withdrawn_back_to_draft(): void
    {
        $inbound = $this->makeInbound(7);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->staff)->post(route('admin.inbounds.withdraw', $inbound))
            ->assertSessionHas('success');

        $this->assertTrue($inbound->refresh()->isDraft());
    }

    public function test_staff_can_not_approve_from_the_inbox(): void
    {
        $inbound = $this->makeInbound(7);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->staff)
            ->post(route('admin.approvals.approve', ['inbound', $inbound->id]))
            ->assertForbidden();

        $this->assertTrue($inbound->refresh()->isPending());
    }

    public function test_an_empty_document_can_not_be_submitted(): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'status' => Inbound::STATUS_DRAFT,
        ]);

        $this->actingAs($this->approver)->post(route('admin.inbounds.submit', $inbound))
            ->assertSessionHas('error');

        $this->assertTrue($inbound->refresh()->isDraft());
    }

    /**
     * Persetujuan bersifat final: tidak ada jalan kembali ke draft, bahkan bagi
     * pemegang izin tertinggi. Koreksi dilakukan lewat dokumen penyesuaian stok
     * supaya jejak audit tetap utuh.
     */
    public function test_an_approved_document_can_not_be_returned_to_draft(): void
    {
        $inbound = $this->makeInbound(10);
        $this->actingAs($this->approver)->post(route('admin.inbounds.submit', $inbound));

        foreach (['unpost', 'withdraw'] as $attempt) {
            $this->actingAs($this->approver)->post("/admin/inbounds/{$inbound->id}/{$attempt}");
        }

        $inbound->refresh();

        $this->assertTrue($inbound->isPosted());
        $this->assertNotNull($inbound->approved_at);
        $this->assertSame(10, $this->product->refresh()->stock);
    }

    /**
     * Saringan jenis dokumen bertahan setelah satu keputusan diambil —
     * kalau tidak, penyetuju harus menyaring ulang tiap kali menyetujui.
     */
    public function test_the_inbox_filter_survives_a_decision(): void
    {
        $inbound = $this->makeInbound(10);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->approver)
            ->post(route('admin.approvals.approve', ['inbound', $inbound->id]), ['filter' => 'inbound'])
            ->assertRedirect(route('admin.approvals.index', ['type' => 'inbound']));
    }

    public function test_a_decision_without_a_filter_returns_to_the_whole_inbox(): void
    {
        $inbound = $this->makeInbound(10);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->approver)
            ->post(route('admin.approvals.approve', ['inbound', $inbound->id]))
            ->assertRedirect(route('admin.approvals.index'));
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_approval_inbox_lists_pending_documents(): void
    {
        $inbound = $this->makeInbound(10);
        $this->actingAs($this->staff)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->approver)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee($inbound->code)
            ->assertSee('Menunggu persetujuan');
    }

    public function test_the_inbox_is_empty_when_nothing_is_pending(): void
    {
        $this->actingAs($this->approver)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('Tidak ada yang menunggu');
    }

    /* --------------------------------------------------- helpers --------- */

    protected function makeInbound(int $quantity): Inbound
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'status' => Inbound::STATUS_DRAFT,
        ]);

        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        return $inbound;
    }
}
