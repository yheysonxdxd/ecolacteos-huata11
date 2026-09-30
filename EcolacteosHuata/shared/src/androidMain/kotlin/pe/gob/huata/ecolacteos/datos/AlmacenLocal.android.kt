package pe.gob.huata.ecolacteos.datos

import android.content.Context
import android.content.SharedPreferences

actual object AlmacenLocal {
    private var prefs: SharedPreferences? = null

    // solo para las pruebas en la PC (sin iniciar(): no hay SharedPreferences ni Keystore)
    private val memoria = mutableMapOf<String, String>()

    /** Llamar una vez al abrir la app (MainActivity.onCreate). */
    fun iniciar(context: Context) {
        prefs = context.applicationContext.getSharedPreferences("ecolacteos_local", Context.MODE_PRIVATE)
        // lo guardado antes del cifrado se cifra apenas abre la app
        prefs?.all?.forEach { (k, v) -> if (v is String && !Cifrado.estaCifrado(v)) guardar(k, v) }
    }

    // todo se guarda cifrado (ver Cifrado); lo de antes del cifrado se lee y se vuelve a guardar cifrado
    actual fun leer(clave: String): String? {
        val p = prefs ?: return memoria[clave]
        val crudo = p.getString(clave, null) ?: return null
        if (Cifrado.estaCifrado(crudo)) return Cifrado.descifrar(crudo)
        guardar(clave, crudo)
        return crudo
    }

    // commit (no apply): la entrega queda escrita en disco antes de seguir.
    // Si el Keystore fallara, se guarda sin cifrar: perder una entrega es peor.
    actual fun guardar(clave: String, valor: String) {
        val p = prefs ?: run { memoria[clave] = valor; return }
        val cifrado = runCatching { Cifrado.cifrar(valor) }.getOrElse { valor }
        p.edit().putString(clave, cifrado).commit()
    }

    actual fun borrar(clave: String) {
        val p = prefs ?: run { memoria.remove(clave); return }
        p.edit().remove(clave).commit()
    }
}
