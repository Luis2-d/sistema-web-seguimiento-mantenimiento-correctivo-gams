<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private function query(Request $r)
    {
        $r->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])],
            'category' => 'nullable|in:hardware,software,connectivity',
            'status' => 'nullable|in:pending,diagnosis,waiting_parts,in_progress,resolved,closed,cancelled',
        ]);
        $q = Ticket::with(['school', 'technician'])->latest();
        if ($r->user()->isDirector()) {
            $q->where('unidad_educativa_id', $r->user()->school_id);
        }
        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->from);
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->to);
        }
        if ($r->filled('category')) {
            $q->where('categoria', Ticket::databaseValue('category', $r->category));
        }
        if ($r->filled('status')) {
            $q->where('estado', Ticket::databaseValue('status', $r->status));
        }

        return $q;
    }

    public function index(Request $r)
    {
        $rows = $this->query($r)->get();

        return view('reports.index', ['rows' => $rows, 'byStatus' => $rows->countBy('status'), 'byCategory' => $rows->countBy('category')]);
    }

    public function export(Request $r)
    {
        $rows = $this->query($r)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Código', 'Fecha', 'Unidad educativa', 'Asunto', 'Categoría', 'Prioridad', 'Estado', 'Técnico']);
            foreach ($rows as $t) {
                $cells = [$t->code, $t->created_at->format('Y-m-d H:i'), $t->school->name, $t->subject, $t->category, $t->priority, $t->status, $t->technician?->name];
                // Los campos escritos por usuarios deben abrirse como texto en una hoja de cálculo.
                fputcsv($out, array_map(static function ($value) {
                    $text = (string) $value;

                    return preg_match('/^(?:[\t\r\n]|\s*[=+@-])/u', $text) ? "'".$text : $text;
                }, $cells));
            }
            fclose($out);
        }, 'reporte_mantenimiento_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
