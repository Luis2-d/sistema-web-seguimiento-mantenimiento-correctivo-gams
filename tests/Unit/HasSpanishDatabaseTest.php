<?php

namespace Tests\Unit;

use App\Models\Concerns\HasSpanishDatabase;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HasSpanishDatabaseTest extends TestCase
{
    public static function statusTranslations(): array
    {
        return [
            'pending' => ['pending', 'pendiente'],
            'diagnosis' => ['diagnosis', 'diagnostico'],
            'waiting_parts' => ['waiting_parts', 'espera_repuestos'],
            'in_progress' => ['in_progress', 'en_progreso'],
            'resolved' => ['resolved', 'solucionado'],
            'closed' => ['closed', 'cerrado'],
            'cancelled' => ['cancelled', 'cancelado'],
        ];
    }

    #[DataProvider('statusTranslations')]
    public function test_ticket_status_translates_to_database_and_back(
        string $applicationStatus,
        string $databaseStatus,
    ): void {
        $ticket = new Ticket;
        $ticket->status = $applicationStatus;

        $this->assertSame($databaseStatus, $ticket->getAttributes()['estado']);
        $this->assertArrayNotHasKey('status', $ticket->getAttributes());
        $this->assertSame($applicationStatus, $ticket->status);
        $this->assertSame($databaseStatus, Ticket::databaseValue('status', $applicationStatus));
    }

    public function test_unknown_status_is_preserved_by_the_translation_layer(): void
    {
        // Validation belongs to the controller; this trait only translates values.
        $ticket = new Ticket;
        $ticket->status = 'unknown_status';

        $this->assertSame('unknown_status', $ticket->getAttributes()['estado']);
        $this->assertSame('unknown_status', $ticket->status);
        $this->assertSame('unknown_status', Ticket::databaseValue('status', 'unknown_status'));
    }

    public function test_mapped_attribute_without_value_map_preserves_its_value(): void
    {
        $ticket = new Ticket;
        $ticket->subject = 'Equipo sin acceso a la red';

        $this->assertSame('Equipo sin acceso a la red', $ticket->getAttributes()['asunto']);
        $this->assertSame('Equipo sin acceso a la red', $ticket->subject);
        $this->assertSame('Equipo sin acceso a la red', Ticket::databaseValue('subject', 'Equipo sin acceso a la red'));
    }

    public function test_attribute_without_mapping_keeps_its_original_key(): void
    {
        $ticket = new Ticket;
        $ticket->id = 87;

        $this->assertSame(87, $ticket->getAttributes()['id']);
        $this->assertSame(87, $ticket->id);
        $this->assertSame(87, Ticket::databaseValue('id', 87));
    }

    public function test_null_value_is_preserved(): void
    {
        $ticket = new Ticket;
        $ticket->status = null;

        $this->assertArrayHasKey('estado', $ticket->getAttributes());
        $this->assertNull($ticket->getAttributes()['estado']);
        $this->assertNull($ticket->status);
        $this->assertNull(Ticket::databaseValue('status', null));
    }

    public function test_trait_supports_a_model_without_mapping_properties(): void
    {
        $model = new class extends Model
        {
            use HasSpanishDatabase;
        };

        $model->reference = 'REF-001';

        $this->assertSame('REF-001', $model->getAttributes()['reference']);
        $this->assertSame('REF-001', $model->reference);
        $this->assertSame('REF-001', $model::databaseValue('reference', 'REF-001'));
    }
}
