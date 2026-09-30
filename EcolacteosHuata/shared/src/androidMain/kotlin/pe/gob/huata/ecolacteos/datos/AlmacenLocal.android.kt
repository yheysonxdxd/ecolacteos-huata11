package pe.gob.huata.ecolacteos.datos

import android.content.Context
import android.content.SharedPreferences

actual object AlmacenLocal {
    private var prefs: SharedPreferences? = null

    /** Llamar una vez al abrir la app (MainActivity.onCreate). */
    fun iniciar(context: Context) {
        prefs = context.applicationContext.getSharedPreferences("ecolacteos_local", Context.MODE_PRIVATE)
    }

    actual fun leer(clave: String): String? = prefs?.getString(clave, null)

    // commit (no apply): la entrega queda escrita en disco antes de seguir
    actual fun guardar(clave: String, valor: String) {
        prefs?.edit()?.putString(clave, valor)?.commit()
    }

    actual fun borrar(clave: String) {
        prefs?.edit()?.remove(clave)?.commit()
    }
}
