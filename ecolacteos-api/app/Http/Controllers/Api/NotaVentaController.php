<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NotaVentaMail;
use App\Services\NotaVentaPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Nota de venta en PDF (comprobante interno, NO es boleta electrónica SUNAT).
 *
 * 1. La app pide GET /api/v1/compras/ventas/{id}/nota (con sesión: Compras o
 *    Admin) y recibe un enlace firmado que vale 30 minutos.
 * 2. Abre ese enlace en el navegador del celular, que muestra el PDF para
 *    imprimirlo o mandarlo por WhatsApp. El enlace no necesita sesión, por eso
 *    va firmado: sin la firma (o vencido) responde 403.
 * 3. Opcional: POST /api/v1/compras/ventas/{id}/enviar-nota { correo } la manda
 *    por correo al cliente (MAIL_* del .env; con MAIL_MAILER=log solo se anota).
 *
 * El enlace es relativo (sin dominio) porque el celular entra por la IP de la
 * red local, que el servidor no conoce.
 */
class NotaVentaController extends Controller
{
    public function __construct(private NotaVentaPdf $notas) {}

    public static function numero(int $ventaId): string
    {
        return NotaVentaPdf::numero($ventaId);
    }

    /** GET /api/v1/compras/ventas/{id}/nota */
    public function enlace(int $id)
    {
        abort_unless(DB::table('ventas')->where('id', $id)->exists(), 404, 'Venta no encontrada.');

        return response()->json([
            'numero' => self::numero($id),
            'url'    => URL::temporarySignedRoute('nota-venta.pdf', now()->addMinutes(30), ['id' => $id], absolute: false),
        ]);
    }

    /** GET /api/v1/notas-venta/{id}.pdf?expires=…&signature=… */
    public function pdf(int $id)
    {
        $datos = $this->notas->datos($id);
        abort_unless($datos, 404);

        return $this->notas->pdf($datos)->stream($datos['numero'] . '.pdf');
    }

    /** POST /api/v1/compras/ventas/{id}/enviar-nota { correo } */
    public function enviar(Request $r, int $id)
    {
        $d = $r->validate(['correo' => ['required', 'email', 'max:120']], [
            'correo.required' => 'Escribe el correo del cliente.',
            'correo.email'    => 'Ese correo no es válido.',
        ]);
        abort_unless(DB::table('ventas')->where('id', $id)->exists(), 404, 'Venta no encontrada.');

        $error = self::mandar($this->notas, $id, $d['correo']);
        abort_if($error, 502, $error);

        return response()->json(['enviado' => true, 'correo' => $d['correo']]);
    }

    /**
     * Manda la nota por correo y, si el cliente es un mayorista (tiene ficha
     * propia), le guarda el correo para la próxima. Devuelve el error o null.
     * Nunca lanza: un correo que falla no debe deshacer la venta.
     */
    public static function mandar(NotaVentaPdf $notas, int $ventaId, string $correo): ?string
    {
        try {
            $datos = $notas->datos($ventaId);
            Mail::to($correo)->send(new NotaVentaMail($datos, $notas->pdf($datos)->output()));

            $cliente = DB::table('ventas as v')->join('clientes as c', 'c.id', '=', 'v.cliente_id')
                ->where('v.id', $ventaId)->first(['c.id', 'c.tipo']);
            if ($cliente && $cliente->tipo === 'MAYORISTA') {
                DB::table('clientes')->where('id', $cliente->id)->update(['email' => $correo, 'updated_at' => now()]);
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar la nota de venta', ['venta' => $ventaId, 'error' => $e->getMessage()]);

            return 'No se pudo enviar el correo. Revisa la conexión a internet del servidor o su configuración de correo.';
        }
    }
}
