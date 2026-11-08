package gallery.memories.documents

import android.content.Context
import android.net.Uri
import gallery.memories.data.local.secure.Credential
import gallery.memories.data.local.secure.SecureCredentialStore
import gallery.memories.data.remote.assets.AssetCache
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.data.remote.http.SessionCookieJar
import okhttp3.Call
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import java.io.IOException
import java.util.concurrent.TimeUnit

internal data class RemoteAlbum(
    val id: Int,
    val name: String,
    val owner: String,
    val ownerDisplay: String,
)

internal data class RemoteAlbumFile(
    val fileId: Long,
    val etag: String,
    val name: String,
    val displayName: String,
    val mimeType: String,
    val size: Long,
    val lastModified: Long,
)

internal class AlbumDocumentsRepository(context: Context) {
    private val appContext = context.applicationContext
    private val credentials = SecureCredentialStore(appContext)
    private val auth = AuthState()
    private val clients = HttpClients(auth, SessionCookieJar(appContext, credentials))
    private var activeCredential: Credential? = null

    fun accountUid(): String {
        val current = credential()
        return current.uid ?: current.username
    }

    fun serverId(): String {
        val url = credential().url
        return AssetCache.originOf(url) + AssetCache.webrootOf(url)
    }

    fun albums(): List<RemoteAlbum> {
        val current = credential()
        val request = request(current.url.trimEnd('/') + "/api/clusters/albums").get().build()
        clients.client().newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw IOException("Album list failed: HTTP ${response.code}")
            val array = JSONArray(response.body.string())
            return List(array.length()) { index ->
                val item = array.getJSONObject(index)
                RemoteAlbum(
                    id = item.getInt("album_id"),
                    name = item.getString("name"),
                    owner = item.getString("user"),
                    ownerDisplay = item.optString("user_display").ifBlank { item.getString("user") },
                )
            }
        }
    }

    fun files(album: RemoteAlbum): List<RemoteAlbumFile> {
        val current = credential()
        val albumQuery = Uri.encode("${album.owner}/${album.name}")
        val daysRequest = request("${current.url.trimEnd('/')}/api/days?albums=$albumQuery").get().build()
        val dayIds = clients.client().newCall(daysRequest).execute().use { response ->
            if (!response.isSuccessful) throw IOException("Album days failed: HTTP ${response.code}")
            val days = JSONArray(response.body.string())
            List(days.length()) { days.getJSONObject(it).getLong("dayid") }
        }

        val files = mutableListOf<RemoteAlbumFile>()
        for (dayChunk in dayIds.chunked(40)) {
            val path = dayChunk.joinToString(",")
            val dayRequest = request("${current.url.trimEnd('/')}/api/days/$path?albums=$albumQuery").get().build()
            clients.client().newCall(dayRequest).execute().use { response ->
                if (!response.isSuccessful) throw IOException("Album photos failed: HTTP ${response.code}")
                val photos = JSONArray(response.body.string())
                for (index in 0 until photos.length()) {
                    val photo = photos.getJSONObject(index)
                    val basename = photo.optString("basename")
                    val fileId = photo.getLong("fileid")
                    files.add(
                        RemoteAlbumFile(
                            name = "$fileId-$basename",
                            displayName = basename,
                            mimeType = photo.optString("mimetype").ifBlank { "application/octet-stream" },
                            size = photo.optLong("size"),
                            lastModified = photo.optLong("epoch") * 1000,
                        ),
                    )
                }
            }
        }
        return files
    }

    fun openFileCall(album: RemoteAlbum, fileName: String): Call {
        val current = credential()
        val uid = current.uid ?: current.username
        val albumType = if (album.owner == uid) "albums" else "sharedalbums"
        val albumName = if (album.owner == uid) album.name else "${album.name} (${album.owner})"
        val path = "/photos/${encodeSegment(uid)}/$albumType/${encodeSegment(albumName)}/${encodeSegment(fileName)}"
        val client = clients.client().newBuilder().readTimeout(60, TimeUnit.SECONDS).build()
        return client.newCall(request(davUrl(current.url, path)).get().build())
    }
    fun previewUrl(file: RemoteAlbumFile, size: Int): String {
        val current = credential()
        val fileId = file.fileId
        val etag = file.etag
        val base = current.url.trimEnd('/') + "/apps/memories/api/image/preview/$fileId"
        val builder = Uri.parse(base).buildUpon()
            .appendQueryParameter("c", etag)
            .appendQueryParameter("x", size.toString())
            .appendQueryParameter("y", size.toString())
            .appendQueryParameter("a", "1")
        return builder.build().toString()
    }
    private fun credential(): Credential {
        val current = credentials.getCredentials() ?: throw IOException("Sign in to Memories to browse albums")
        if (current != activeCredential) {
            clients.clearCookies()
            auth.build(current.url, current.trustAll)
            auth.setAuthHeader(current.username to current.token, current.uid)
            clients.rebuild()
            activeCredential = current
        }
        return current
    }

    private fun request(url: String): Request.Builder = Request.Builder()
        .url(url)
        .header("User-Agent", "MemoriesNative")
        .header("Authorization", auth.authHeader() ?: "")
        .header("OCS-APIREQUEST", "true")

    private fun davUrl(baseUrl: String, path: String): String =
        AssetCache.originOf(baseUrl) + AssetCache.webrootOf(baseUrl) + "/remote.php/dav" + path

    private fun encodeSegment(segment: String): String = Uri.encode(segment)
}