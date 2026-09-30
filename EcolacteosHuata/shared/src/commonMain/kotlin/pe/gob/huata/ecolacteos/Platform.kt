package pe.gob.huata.ecolacteos

interface Platform {
    val name: String
}

expect fun getPlatform(): Platform