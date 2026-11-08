package gallery.memories.documents

import android.content.Context
import android.content.res.AssetFileDescriptor
import android.database.Cursor
import android.database.MatrixCursor
import android.graphics.BitmapFactory
import android.graphics.Point
import android.os.CancellationSignal
import android.os.ParcelFileDescriptor
import android.provider.DocumentsContract
import android.provider.DocumentsProvider
import android.util.Base64
import android.webkit.MimeTypeMap
import gallery.memories.R
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.io.File
import java.io.FileNotFoundException
import java.io.IOException

class MemoriesDocumentsProvider : DocumentsProvider() {
    private lateinit var repository: AlbumDocumentsRepository

    companion object {
        private const val ROOT_DOCUMENT_ID = "root"
        private const val ROOT_ID_PREFIX = "memories"
        private const val TYPE_ALBUM = "album"
        private const val TYPE_FILE = "file"
        private const val MIME_TYPE_DEFAULT = "*/*"
        private val ROOT_PROJECTION = arrayOf(
            DocumentsContract.Root.COLUMN_ROOT_ID,
            DocumentsContract.Root.COLUMN_DOCUMENT_ID,
            DocumentsContract.Root.COLUMN_TITLE,
            DocumentsContract.Root.COLUMN_SUMMARY,
            DocumentsContract.Root.COLUMN_FLAGS,
            DocumentsContract.Root.COLUMN_ICON,
            DocumentsContract.Root.COLUMN_MIME_TYPES,
        )
        private val DOCUMENT_PROJECTION = arrayOf(
            DocumentsContract.Document.COLUMN_DOCUMENT_ID,
            DocumentsContract.Document.COLUMN_DISPLAY_NAME,
            DocumentsContract.Document.COLUMN_MIME_TYPE,
            DocumentsContract.Document.COLUMN_SIZE,
            DocumentsContract.Document.COLUMN_LAST_MODIFIED,
            DocumentsContract.Document.COLUMN_FLAGS,
            DocumentsContract.Document.COLUMN_SUMMARY,
        )

        fun notifyRootsChanged(context: Context) {
            context.contentResolver.notifyChange(
                DocumentsContract.buildRootsUri("${context.packageName}.documents"),
                null,
            )
        }
    }

    private data class DocumentRef(
        val type: String,
        val server: String,
        val uid: String,
        val albumId: Int,
        val albumName: String,
        val owner: String,
        val fileName: String = "",
    )

    override fun onCreate(): Boolean {
        repository = AlbumDocumentsRepository(requireNotNull(context))
        return true
    }

    override fun queryRoots(projection: Array<out String>?): Cursor {
        val cursor = MatrixCursor(projection ?: ROOT_PROJECTION)
        try {
            val server = repository.serverId()
            val uid = repository.accountUid()
            val row = cursor.newRow()
            add(row, cursor, mapOf(
                DocumentsContract.Root.COLUMN_ROOT_ID to rootId(server, uid),
                DocumentsContract.Root.COLUMN_DOCUMENT_ID to ROOT_DOCUMENT_ID,
                DocumentsContract.Root.COLUMN_TITLE to context?.getString(R.string.app_name),
                DocumentsContract.Root.COLUMN_SUMMARY to "Albums on $server",
                DocumentsContract.Root.COLUMN_FLAGS to 0,
                DocumentsContract.Root.COLUMN_ICON to R.mipmap.ic_launcher,
                DocumentsContract.Root.COLUMN_MIME_TYPES to "image/*\nvideo/*",
            ))
        } catch (_: IOException) {
            cursor.close()
            return MatrixCursor(projection ?: ROOT_PROJECTION)
        }
        return cursor
    }

    override fun queryDocument(documentId: String, projection: Array<out String>?): Cursor {
        val cursor = MatrixCursor(projection ?: DOCUMENT_PROJECTION)
        if (documentId == ROOT_DOCUMENT_ID) {
            add(cursor.newRow(), cursor, directoryValues(ROOT_DOCUMENT_ID, context?.getString(R.string.app_name) ?: "Memories"))
            return cursor
        }

        val ref = parseDocumentId(documentId)
        requireCurrentAccount(ref)
        val album = findAlbum(ref)
        if (ref.type == TYPE_ALBUM) {
            add(cursor.newRow(), cursor, directoryValues(documentId, album.name))
        } else {
            val file = repository.files(album).firstOrNull { it.name == ref.fileName }
                ?: throw FileNotFoundException("Album item no longer exists")
            add(cursor.newRow(), cursor, fileValues(documentId, file))
        }
        return cursor
    }

    override fun queryChildDocuments(
        parentDocumentId: String,
        projection: Array<out String>?,
        sortOrder: String?,
    ): Cursor {
        val cursor = MatrixCursor(projection ?: DOCUMENT_PROJECTION)
        if (parentDocumentId == ROOT_DOCUMENT_ID) {
            try {
                repository.albums().forEach { album ->
                    val id = albumDocumentId(album)
                    val summary = if (album.owner == repository.accountUid()) null else "Shared by ${album.ownerDisplay}"
                    add(cursor.newRow(), cursor, directoryValues(id, album.name, summary))
                }
            } catch (e: IOException) {
                cursor.close()
                throw notFound(e)
            }
            return cursor
        }

        val ref = parseDocumentId(parentDocumentId)
        if (ref.type != TYPE_ALBUM) throw FileNotFoundException("Not a directory")
        requireCurrentAccount(ref)
        val album = findAlbum(ref)
        try {
            repository.files(album).forEach { file ->
                add(cursor.newRow(), cursor, fileValues(fileDocumentId(album, file.name), file))
            }
        } catch (e: IOException) {
            cursor.close()
            throw notFound(e)
        }
        return cursor
    }

    override fun isChildDocument(parentDocumentId: String, documentId: String): Boolean {
        return try {
            val child = parseDocumentId(documentId)
            val belongsToAccount = child.server == repository.serverId() && child.uid == repository.accountUid()
            if (parentDocumentId == ROOT_DOCUMENT_ID) {
                belongsToAccount
            } else {
                val parent = parseDocumentId(parentDocumentId)
                belongsToAccount && parent.type == TYPE_ALBUM && child.type == TYPE_FILE &&
                    parent.server == child.server && parent.uid == child.uid &&
                    parent.albumId == child.albumId && parent.owner == child.owner && parent.albumName == child.albumName
            }
        } catch (_: Exception) {
            false
        }
    }

    override fun openDocument(documentId: String, mode: String, signal: CancellationSignal?): ParcelFileDescriptor {
        if (mode != "r" && mode != "rt") throw FileNotFoundException("Documents are read-only")
        val ref = parseDocumentId(documentId)
        if (ref.type != TYPE_FILE) throw FileNotFoundException("Not a file")
        requireCurrentAccount(ref)
        val album = findAlbum(ref)
        val call = try {
            repository.openFileCall(album, ref.fileName)
        } catch (e: IOException) {
            throw notFound(e)
        }
        val pipe = ParcelFileDescriptor.createReliablePipe()
        signal?.setOnCancelListener { call.cancel() }
        Thread({
            val output = ParcelFileDescriptor.AutoCloseOutputStream(pipe[1])
            try {
                call.execute().use { response ->
                    if (!response.isSuccessful) throw IOException("File download failed: HTTP ${response.code}")
                    response.body.byteStream().use { input -> input.copyTo(output) }
                }
                output.close()
            } catch (e: Exception) {
                try {
                    pipe[1].closeWithError(e.message ?: "File download failed")
                } catch (_: Exception) {}
            } finally {
                signal?.setOnCancelListener(null)
            }
        }, "Memories document stream").start()
        return pipe[0]
    }

    override fun openDocumentThumbnail(documentId: String, sizeHint: Point?, signal: CancellationSignal?): AssetFileDescriptor {
        val ref = parseDocumentId(documentId)
        if (ref.type != TYPE_FILE) throw FileNotFoundException("Not a file")
        requireCurrentAccount(ref)
        val album = findAlbum(ref)
        val file = repository.files(album).firstOrNull { it.name == ref.fileName }
            ?: throw FileNotFoundException("Album item no longer exists")
        if (file.fileId <= 0L || file.etag.isBlank()) {
            throw FileNotFoundException("Preview unavailable")
        }
        val target = maxOf(sizeHint?.x ?: 256, sizeHint?.y ?: 256, 256)
        val tmp = repository.thumbnailFile(file, target)
        val pfd = ParcelFileDescriptor.open(tmp, ParcelFileDescriptor.MODE_READ_ONLY)
        return AssetFileDescriptor(pfd, 0, AssetFileDescriptor.UNKNOWN_LENGTH)
    }

    private fun findAlbum(ref: DocumentRef): RemoteAlbum {
        val album = try {
            repository.albums().firstOrNull {
                it.id == ref.albumId && it.owner == ref.owner && it.name == ref.albumName
            }
        } catch (e: IOException) {
            throw notFound(e)
        } ?: throw FileNotFoundException("Album no longer exists")
        return album
    }

    private fun requireCurrentAccount(ref: DocumentRef) {
        try {
            if (ref.server != repository.serverId() || ref.uid != repository.accountUid()) {
                throw FileNotFoundException("Document belongs to another account")
            }
        } catch (e: IOException) {
            throw notFound(e)
        }
    }

    private fun albumDocumentId(album: RemoteAlbum): String = encodeDocumentId(
        DocumentRef(TYPE_ALBUM, repository.serverId(), repository.accountUid(), album.id, album.name, album.owner),
    )

    private fun fileDocumentId(album: RemoteAlbum, fileName: String): String = encodeDocumentId(
        DocumentRef(TYPE_FILE, repository.serverId(), repository.accountUid(), album.id, album.name, album.owner, fileName),
    )

    private fun encodeDocumentId(ref: DocumentRef): String = Base64.encodeToString(
        JSONObject()
            .put("type", ref.type)
            .put("server", ref.server)
            .put("uid", ref.uid)
            .put("albumId", ref.albumId)
            .put("albumName", ref.albumName)
            .put("owner", ref.owner)
            .put("fileName", ref.fileName)
            .toString().toByteArray(),
        Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING,
    )

    private fun parseDocumentId(documentId: String): DocumentRef = try {
        val json = JSONObject(String(Base64.decode(documentId, Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING)))
        val type = json.getString("type")
        if (type != TYPE_ALBUM && type != TYPE_FILE) throw IllegalArgumentException("Unknown document type")
        DocumentRef(
            type = type,
            server = json.getString("server"),
            uid = json.getString("uid"),
            albumId = json.getInt("albumId"),
            albumName = json.getString("albumName"),
            owner = json.getString("owner"),
            fileName = json.optString("fileName"),
        )
    } catch (e: Exception) {
        throw FileNotFoundException("Invalid document ID").also { it.initCause(e) }
    }

    private fun directoryValues(id: String, name: String, summary: String? = null) = mapOf(
        DocumentsContract.Document.COLUMN_DOCUMENT_ID to id,
        DocumentsContract.Document.COLUMN_DISPLAY_NAME to name,
        DocumentsContract.Document.COLUMN_MIME_TYPE to DocumentsContract.Document.MIME_TYPE_DIR,
        DocumentsContract.Document.COLUMN_FLAGS to 0,
        DocumentsContract.Document.COLUMN_SUMMARY to summary,
    )

    private fun fileValues(id: String, file: RemoteAlbumFile) = mapOf(
        DocumentsContract.Document.COLUMN_DOCUMENT_ID to id,
        DocumentsContract.Document.COLUMN_DISPLAY_NAME to file.displayName,
        DocumentsContract.Document.COLUMN_MIME_TYPE to mimeType(file),
        DocumentsContract.Document.COLUMN_SIZE to file.size,
        DocumentsContract.Document.COLUMN_LAST_MODIFIED to file.lastModified,
        DocumentsContract.Document.COLUMN_FLAGS to 0,
    )

    private fun mimeType(file: RemoteAlbumFile): String {
        if (file.mimeType != MIME_TYPE_DEFAULT && file.mimeType != "application/octet-stream") return file.mimeType
        return MimeTypeMap.getSingleton().getMimeTypeFromExtension(file.displayName.substringAfterLast('.', "").lowercase())
            ?: MIME_TYPE_DEFAULT
    }

    private fun add(row: MatrixCursor.RowBuilder, cursor: MatrixCursor, values: Map<String, Any?>) {
        cursor.columnNames.forEach { row.add(values[it]) }
    }

    private fun rootId(server: String, uid: String): String = ROOT_ID_PREFIX + Base64.encodeToString(
        "$server\u0000$uid".toByteArray(),
        Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING,
    )

    private fun notFound(error: IOException): FileNotFoundException =
        FileNotFoundException(error.message ?: "Remote document unavailable").also { it.initCause(error) }
}