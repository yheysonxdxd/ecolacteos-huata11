package pe.gob.huata.ecolacteos

import pe.gob.huata.ecolacteos.datos.AlmacenLocal
import pe.gob.huata.ecolacteos.datos.Proveedor
import pe.gob.huata.ecolacteos.ui.acopiador.*
import pe.gob.huata.ecolacteos.ui.componentes.fechaCorta
import kotlin.test.*
import kotlin.time.Clock
import kotlin.time.Duration.Companion.days
import kotlin.time.Duration.Companion.hours
import kotlin.time.ExperimentalTime

/**
 * Lo que el acopiador hace sin señal: registrar, guardar en el teléfono,
 * recuperar tras cerrar la app y el envío simulado del modo demo.
 * (En la PC el almacén local usa memoria; en el celular, SharedPreferences cifradas.)
 */
@OptIn(ExperimentalTime::class)
class AcopioOfflineTest {
    private val clave = "acopio_prueba"

    private fun prov(id: Long, dni: String, nombre: String) =
        Proveedor(id, "p$id", nombre, dni, comunidadId = 1, vehiculoId = 1, promedioLitros = 15.0)

    private fun diaPeru(menos: Int = 0) = (Clock.System.now() - 5.hours - menos.days).toString().take(10)

    @BeforeTest
    fun datos() {
        Demo.proveedores = listOf(
            prov(1, "40112233", "Rosa Quispe Mamani"),
            prov(2, "40223344", "Julián Apaza Condori"),
            prov(3, "40334455", "Elena Choque Tito"),
        )
        AlmacenLocal.borrar(clave)
        ResultadoSync.mensaje = null
    }

    // --- estado del día ---

    @Test
    fun cuenta_los_registros_sin_enviar() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5)
        e.guardar(Demo.proveedores[1], 12.0)
        assertEquals(2, e.pendientes)
        assertEquals(30.5, e.litrosHoy)
    }

    @Test
    fun no_entrego_guarda_cero_litros() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5, ausente = true)
        assertEquals(0.0, e.entregas.getValue(1).litros)
        assertTrue(e.entregas.getValue(1).ausente)
    }

    @Test
    fun corregir_una_enviada_la_vuelve_pendiente() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5)
        e.enviados.add(1)
        assertEquals(0, e.pendientes)

        e.guardar(Demo.proveedores[0], 20.0)
        assertEquals(1, e.pendientes)
        assertEquals(20.0, e.entregas.getValue(1).litros)
    }

    // --- modo demo ---

    @Test
    fun envio_demo_solo_marca_como_enviado() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5)
        e.guardar(Demo.proveedores[1], 12.0)

        simularEnvioDemo(e)

        assertEquals(0, e.pendientes)
        assertContains(ResultadoSync.mensaje!!, "no se guardan en la base de datos")
    }

    // --- guardado en el teléfono ---

    @Test
    fun cerrar_y_abrir_la_app_recupera_todo() {
        val antes = EstadoAcopio()
        antes.guardar(Demo.proveedores[0], 18.5)
        antes.guardar(Demo.proveedores[1], 0.0, ausente = true)
        antes.enviados.add(2)
        guardar(clave, antes.entregas.toMap(), antes.enviados.toList())

        val despues = EstadoAcopio() // la app se cerró: memoria vacía
        val atrasadas = restaurar(despues, clave)

        assertEquals(0, atrasadas)
        assertEquals(setOf(1L, 2L), despues.entregas.keys)
        assertEquals(18.5, despues.entregas.getValue(1).litros)
        assertTrue(despues.entregas.getValue(2).ausente)
        assertEquals(listOf(2L), despues.enviados.toList())
        assertEquals("Se recuperaron 1 registros guardados sin enviar", ResultadoSync.mensaje)
    }

    @Test
    fun el_uuid_de_servidor_no_cambia_al_reabrir() {
        // si cambiara, reenviar tras un corte duplicaría la entrega en el servidor
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5)
        guardar(clave, e.entregas.toMap(), e.enviados.toList())
        val uuidServidor = uuidPorEntrega.getValue(e.entregas.getValue(1).uuid)

        val r1 = EstadoAcopio().also { restaurar(it, clave) }
        guardar(clave, r1.entregas.toMap(), r1.enviados.toList())
        val r2 = EstadoAcopio().also { restaurar(it, clave) }

        assertEquals(uuidServidor, r1.entregas.getValue(1).uuid)
        assertEquals(uuidServidor, r2.entregas.getValue(1).uuid)
        assertEquals(uuidServidor, uuidPorEntrega[uuidServidor])
    }

    @Test
    fun guarda_la_hora_real_de_registro() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5)
        HoraRegistro.poner(e.entregas.getValue(1).uuid, "${diaPeru()} 05:40:00")
        guardar(clave, e.entregas.toMap(), e.enviados.toList())

        val r = EstadoAcopio().also { restaurar(it, clave) }
        assertEquals("${diaPeru()} 05:40:00", HoraRegistro.de(r.entregas.getValue(1)))
    }

    @Test
    fun de_ayer_lo_enviado_se_descarta_y_lo_pendiente_se_conserva() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5) // ayer, enviada
        e.guardar(Demo.proveedores[1], 12.0) // ayer, SIN enviar
        e.guardar(Demo.proveedores[2], 9.0)  // hoy, sin enviar
        e.entregas.values.take(2).forEach { HoraRegistro.poner(it.uuid, "${diaPeru(1)} 06:00:00") }
        e.enviados.add(1)
        guardar(clave, e.entregas.toMap(), e.enviados.toList())

        val r = EstadoAcopio()
        val atrasadas = restaurar(r, clave)

        assertEquals(1, atrasadas) // la de ayer sin enviar: la app intenta enviarla sola
        assertEquals(setOf(2L, 3L), r.entregas.keys)
    }

    @Test
    fun no_recupera_proveedores_que_ya_no_estan() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[2], 9.0)
        guardar(clave, e.entregas.toMap(), e.enviados.toList())
        Demo.proveedores = Demo.proveedores.take(2)

        val r = EstadoAcopio().also { restaurar(it, clave) }
        assertTrue(r.entregas.isEmpty())
    }

    @Test
    fun no_pisa_lo_que_ya_esta_en_pantalla() {
        val e = EstadoAcopio()
        e.guardar(Demo.proveedores[0], 18.5)
        guardar(clave, e.entregas.toMap(), e.enviados.toList())

        val r = EstadoAcopio()
        r.guardar(Demo.proveedores[0], 25.0) // lo recién anotado manda
        restaurar(r, clave)
        assertEquals(25.0, r.entregas.getValue(1).litros)
    }

    // --- alta de proveedor (modo demo: sin cola de subida) ---

    @Test
    fun alta_de_proveedor_valida_los_datos() {
        assertEquals("Escribe nombre y apellidos", ProveedoresNuevos.agregar("Juan", "41234567", 1, 1, null, null))
        assertEquals("El DNI debe tener 8 dígitos", ProveedoresNuevos.agregar("Juan Pérez", "4123", 1, 1, null, null))
        assertEquals("Ese DNI ya es de Rosa Quispe Mamani", ProveedoresNuevos.agregar("Otra Persona", "40112233", 1, 1, null, null))
        assertEquals(3, Demo.proveedores.size)
    }

    @Test
    fun alta_de_proveedor_lo_agrega_a_la_lista() {
        assertNull(ProveedoresNuevos.agregar("  Juan   Pérez Mamani ", "41234567", 1, 1, 0.0, 4))

        val nuevo = Demo.proveedores.last()
        assertEquals(4, nuevo.id)
        assertEquals("Juan Pérez Mamani", nuevo.nombre)
        assertNull(nuevo.promedioLitros) // 0 = no se sabe
        assertEquals(4, nuevo.vacasOrdeno)
    }

    // --- utilidades ---

    @Test
    fun fecha_corta() {
        assertEquals("14 set", fechaCorta("2026-09-14"))
        assertEquals("1 ene", fechaCorta("2026-01-01 10:00:00"))
    }
}
