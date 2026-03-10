package com.palweb.reservasoffline;

import android.database.Cursor;
import android.os.CancellationSignal;
import androidx.annotation.NonNull;
import androidx.room.CoroutinesRoom;
import androidx.room.EntityInsertionAdapter;
import androidx.room.RoomDatabase;
import androidx.room.RoomSQLiteQuery;
import androidx.room.SharedSQLiteStatement;
import androidx.room.util.CursorUtil;
import androidx.room.util.DBUtil;
import androidx.sqlite.db.SupportSQLiteStatement;
import java.lang.Class;
import java.lang.Exception;
import java.lang.Integer;
import java.lang.Long;
import java.lang.Object;
import java.lang.Override;
import java.lang.String;
import java.lang.SuppressWarnings;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.concurrent.Callable;
import javax.annotation.processing.Generated;
import kotlin.Unit;
import kotlin.coroutines.Continuation;
import kotlinx.coroutines.flow.Flow;

@Generated("androidx.room.RoomProcessor")
@SuppressWarnings({"unchecked", "deprecation"})
public final class QueueDao_Impl implements QueueDao {
  private final RoomDatabase __db;

  private final EntityInsertionAdapter<SyncQueueEntity> __insertionAdapterOfSyncQueueEntity;

  private final SharedSQLiteStatement __preparedStmtOfDelete;

  private final SharedSQLiteStatement __preparedStmtOfBumpAttempt;

  private final SharedSQLiteStatement __preparedStmtOfScheduleRetry;

  public QueueDao_Impl(@NonNull final RoomDatabase __db) {
    this.__db = __db;
    this.__insertionAdapterOfSyncQueueEntity = new EntityInsertionAdapter<SyncQueueEntity>(__db) {
      @Override
      @NonNull
      protected String createQuery() {
        return "INSERT OR REPLACE INTO `sync_queue` (`id`,`opType`,`reservationUuid`,`payloadJson`,`createdAtEpoch`,`attempts`,`nextAttemptAtEpoch`,`lastError`) VALUES (nullif(?, 0),?,?,?,?,?,?,?)";
      }

      @Override
      protected void bind(@NonNull final SupportSQLiteStatement statement,
          @NonNull final SyncQueueEntity entity) {
        statement.bindLong(1, entity.getId());
        if (entity.getOpType() == null) {
          statement.bindNull(2);
        } else {
          statement.bindString(2, entity.getOpType());
        }
        if (entity.getReservationUuid() == null) {
          statement.bindNull(3);
        } else {
          statement.bindString(3, entity.getReservationUuid());
        }
        if (entity.getPayloadJson() == null) {
          statement.bindNull(4);
        } else {
          statement.bindString(4, entity.getPayloadJson());
        }
        statement.bindLong(5, entity.getCreatedAtEpoch());
        statement.bindLong(6, entity.getAttempts());
        statement.bindLong(7, entity.getNextAttemptAtEpoch());
        if (entity.getLastError() == null) {
          statement.bindNull(8);
        } else {
          statement.bindString(8, entity.getLastError());
        }
      }
    };
    this.__preparedStmtOfDelete = new SharedSQLiteStatement(__db) {
      @Override
      @NonNull
      public String createQuery() {
        final String _query = "DELETE FROM sync_queue WHERE id = ?";
        return _query;
      }
    };
    this.__preparedStmtOfBumpAttempt = new SharedSQLiteStatement(__db) {
      @Override
      @NonNull
      public String createQuery() {
        final String _query = "UPDATE sync_queue SET attempts = attempts + 1 WHERE id = ?";
        return _query;
      }
    };
    this.__preparedStmtOfScheduleRetry = new SharedSQLiteStatement(__db) {
      @Override
      @NonNull
      public String createQuery() {
        final String _query = "UPDATE sync_queue SET attempts = attempts + 1, nextAttemptAtEpoch = ?, lastError = ? WHERE id = ?";
        return _query;
      }
    };
  }

  @Override
  public Object insert(final SyncQueueEntity item, final Continuation<? super Long> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Long>() {
      @Override
      @NonNull
      public Long call() throws Exception {
        __db.beginTransaction();
        try {
          final Long _result = __insertionAdapterOfSyncQueueEntity.insertAndReturnId(item);
          __db.setTransactionSuccessful();
          return _result;
        } finally {
          __db.endTransaction();
        }
      }
    }, $completion);
  }

  @Override
  public Object delete(final long id, final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        final SupportSQLiteStatement _stmt = __preparedStmtOfDelete.acquire();
        int _argIndex = 1;
        _stmt.bindLong(_argIndex, id);
        try {
          __db.beginTransaction();
          try {
            _stmt.executeUpdateDelete();
            __db.setTransactionSuccessful();
            return Unit.INSTANCE;
          } finally {
            __db.endTransaction();
          }
        } finally {
          __preparedStmtOfDelete.release(_stmt);
        }
      }
    }, $completion);
  }

  @Override
  public Object bumpAttempt(final long id, final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        final SupportSQLiteStatement _stmt = __preparedStmtOfBumpAttempt.acquire();
        int _argIndex = 1;
        _stmt.bindLong(_argIndex, id);
        try {
          __db.beginTransaction();
          try {
            _stmt.executeUpdateDelete();
            __db.setTransactionSuccessful();
            return Unit.INSTANCE;
          } finally {
            __db.endTransaction();
          }
        } finally {
          __preparedStmtOfBumpAttempt.release(_stmt);
        }
      }
    }, $completion);
  }

  @Override
  public Object scheduleRetry(final long id, final long nextAttemptAt, final String lastError,
      final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        final SupportSQLiteStatement _stmt = __preparedStmtOfScheduleRetry.acquire();
        int _argIndex = 1;
        _stmt.bindLong(_argIndex, nextAttemptAt);
        _argIndex = 2;
        if (lastError == null) {
          _stmt.bindNull(_argIndex);
        } else {
          _stmt.bindString(_argIndex, lastError);
        }
        _argIndex = 3;
        _stmt.bindLong(_argIndex, id);
        try {
          __db.beginTransaction();
          try {
            _stmt.executeUpdateDelete();
            __db.setTransactionSuccessful();
            return Unit.INSTANCE;
          } finally {
            __db.endTransaction();
          }
        } finally {
          __preparedStmtOfScheduleRetry.release(_stmt);
        }
      }
    }, $completion);
  }

  @Override
  public Object all(final Continuation<? super List<SyncQueueEntity>> $completion) {
    final String _sql = "SELECT * FROM sync_queue ORDER BY id ASC";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 0);
    final CancellationSignal _cancellationSignal = DBUtil.createCancellationSignal();
    return CoroutinesRoom.execute(__db, false, _cancellationSignal, new Callable<List<SyncQueueEntity>>() {
      @Override
      @NonNull
      public List<SyncQueueEntity> call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfOpType = CursorUtil.getColumnIndexOrThrow(_cursor, "opType");
          final int _cursorIndexOfReservationUuid = CursorUtil.getColumnIndexOrThrow(_cursor, "reservationUuid");
          final int _cursorIndexOfPayloadJson = CursorUtil.getColumnIndexOrThrow(_cursor, "payloadJson");
          final int _cursorIndexOfCreatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "createdAtEpoch");
          final int _cursorIndexOfAttempts = CursorUtil.getColumnIndexOrThrow(_cursor, "attempts");
          final int _cursorIndexOfNextAttemptAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "nextAttemptAtEpoch");
          final int _cursorIndexOfLastError = CursorUtil.getColumnIndexOrThrow(_cursor, "lastError");
          final List<SyncQueueEntity> _result = new ArrayList<SyncQueueEntity>(_cursor.getCount());
          while (_cursor.moveToNext()) {
            final SyncQueueEntity _item;
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpOpType;
            if (_cursor.isNull(_cursorIndexOfOpType)) {
              _tmpOpType = null;
            } else {
              _tmpOpType = _cursor.getString(_cursorIndexOfOpType);
            }
            final String _tmpReservationUuid;
            if (_cursor.isNull(_cursorIndexOfReservationUuid)) {
              _tmpReservationUuid = null;
            } else {
              _tmpReservationUuid = _cursor.getString(_cursorIndexOfReservationUuid);
            }
            final String _tmpPayloadJson;
            if (_cursor.isNull(_cursorIndexOfPayloadJson)) {
              _tmpPayloadJson = null;
            } else {
              _tmpPayloadJson = _cursor.getString(_cursorIndexOfPayloadJson);
            }
            final long _tmpCreatedAtEpoch;
            _tmpCreatedAtEpoch = _cursor.getLong(_cursorIndexOfCreatedAtEpoch);
            final int _tmpAttempts;
            _tmpAttempts = _cursor.getInt(_cursorIndexOfAttempts);
            final long _tmpNextAttemptAtEpoch;
            _tmpNextAttemptAtEpoch = _cursor.getLong(_cursorIndexOfNextAttemptAtEpoch);
            final String _tmpLastError;
            if (_cursor.isNull(_cursorIndexOfLastError)) {
              _tmpLastError = null;
            } else {
              _tmpLastError = _cursor.getString(_cursorIndexOfLastError);
            }
            _item = new SyncQueueEntity(_tmpId,_tmpOpType,_tmpReservationUuid,_tmpPayloadJson,_tmpCreatedAtEpoch,_tmpAttempts,_tmpNextAttemptAtEpoch,_tmpLastError);
            _result.add(_item);
          }
          return _result;
        } finally {
          _cursor.close();
          _statement.release();
        }
      }
    }, $completion);
  }

  @Override
  public Flow<Integer> countFlow() {
    final String _sql = "SELECT COUNT(*) FROM sync_queue";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 0);
    return CoroutinesRoom.createFlow(__db, false, new String[] {"sync_queue"}, new Callable<Integer>() {
      @Override
      @NonNull
      public Integer call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final Integer _result;
          if (_cursor.moveToFirst()) {
            final Integer _tmp;
            if (_cursor.isNull(0)) {
              _tmp = null;
            } else {
              _tmp = _cursor.getInt(0);
            }
            _result = _tmp;
          } else {
            _result = null;
          }
          return _result;
        } finally {
          _cursor.close();
        }
      }

      @Override
      protected void finalize() {
        _statement.release();
      }
    });
  }

  @NonNull
  public static List<Class<?>> getRequiredConverters() {
    return Collections.emptyList();
  }
}
