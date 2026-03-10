package com.palweb.reservasoffline;

import android.database.Cursor;
import androidx.annotation.NonNull;
import androidx.room.CoroutinesRoom;
import androidx.room.EntityInsertionAdapter;
import androidx.room.RoomDatabase;
import androidx.room.RoomSQLiteQuery;
import androidx.room.util.CursorUtil;
import androidx.room.util.DBUtil;
import androidx.sqlite.db.SupportSQLiteStatement;
import java.lang.Class;
import java.lang.Exception;
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
import kotlin.coroutines.Continuation;
import kotlinx.coroutines.flow.Flow;

@Generated("androidx.room.RoomProcessor")
@SuppressWarnings({"unchecked", "deprecation"})
public final class SyncHistoryDao_Impl implements SyncHistoryDao {
  private final RoomDatabase __db;

  private final EntityInsertionAdapter<SyncHistoryEntity> __insertionAdapterOfSyncHistoryEntity;

  public SyncHistoryDao_Impl(@NonNull final RoomDatabase __db) {
    this.__db = __db;
    this.__insertionAdapterOfSyncHistoryEntity = new EntityInsertionAdapter<SyncHistoryEntity>(__db) {
      @Override
      @NonNull
      protected String createQuery() {
        return "INSERT OR REPLACE INTO `sync_history` (`id`,`action`,`success`,`detail`,`itemsTotal`,`itemsOk`,`createdAtEpoch`) VALUES (nullif(?, 0),?,?,?,?,?,?)";
      }

      @Override
      protected void bind(@NonNull final SupportSQLiteStatement statement,
          @NonNull final SyncHistoryEntity entity) {
        statement.bindLong(1, entity.getId());
        if (entity.getAction() == null) {
          statement.bindNull(2);
        } else {
          statement.bindString(2, entity.getAction());
        }
        statement.bindLong(3, entity.getSuccess());
        if (entity.getDetail() == null) {
          statement.bindNull(4);
        } else {
          statement.bindString(4, entity.getDetail());
        }
        statement.bindLong(5, entity.getItemsTotal());
        statement.bindLong(6, entity.getItemsOk());
        statement.bindLong(7, entity.getCreatedAtEpoch());
      }
    };
  }

  @Override
  public Object insert(final SyncHistoryEntity item, final Continuation<? super Long> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Long>() {
      @Override
      @NonNull
      public Long call() throws Exception {
        __db.beginTransaction();
        try {
          final Long _result = __insertionAdapterOfSyncHistoryEntity.insertAndReturnId(item);
          __db.setTransactionSuccessful();
          return _result;
        } finally {
          __db.endTransaction();
        }
      }
    }, $completion);
  }

  @Override
  public Flow<List<SyncHistoryEntity>> latest(final int limit) {
    final String _sql = "SELECT * FROM sync_history ORDER BY createdAtEpoch DESC LIMIT ?";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 1);
    int _argIndex = 1;
    _statement.bindLong(_argIndex, limit);
    return CoroutinesRoom.createFlow(__db, false, new String[] {"sync_history"}, new Callable<List<SyncHistoryEntity>>() {
      @Override
      @NonNull
      public List<SyncHistoryEntity> call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfAction = CursorUtil.getColumnIndexOrThrow(_cursor, "action");
          final int _cursorIndexOfSuccess = CursorUtil.getColumnIndexOrThrow(_cursor, "success");
          final int _cursorIndexOfDetail = CursorUtil.getColumnIndexOrThrow(_cursor, "detail");
          final int _cursorIndexOfItemsTotal = CursorUtil.getColumnIndexOrThrow(_cursor, "itemsTotal");
          final int _cursorIndexOfItemsOk = CursorUtil.getColumnIndexOrThrow(_cursor, "itemsOk");
          final int _cursorIndexOfCreatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "createdAtEpoch");
          final List<SyncHistoryEntity> _result = new ArrayList<SyncHistoryEntity>(_cursor.getCount());
          while (_cursor.moveToNext()) {
            final SyncHistoryEntity _item;
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpAction;
            if (_cursor.isNull(_cursorIndexOfAction)) {
              _tmpAction = null;
            } else {
              _tmpAction = _cursor.getString(_cursorIndexOfAction);
            }
            final int _tmpSuccess;
            _tmpSuccess = _cursor.getInt(_cursorIndexOfSuccess);
            final String _tmpDetail;
            if (_cursor.isNull(_cursorIndexOfDetail)) {
              _tmpDetail = null;
            } else {
              _tmpDetail = _cursor.getString(_cursorIndexOfDetail);
            }
            final int _tmpItemsTotal;
            _tmpItemsTotal = _cursor.getInt(_cursorIndexOfItemsTotal);
            final int _tmpItemsOk;
            _tmpItemsOk = _cursor.getInt(_cursorIndexOfItemsOk);
            final long _tmpCreatedAtEpoch;
            _tmpCreatedAtEpoch = _cursor.getLong(_cursorIndexOfCreatedAtEpoch);
            _item = new SyncHistoryEntity(_tmpId,_tmpAction,_tmpSuccess,_tmpDetail,_tmpItemsTotal,_tmpItemsOk,_tmpCreatedAtEpoch);
            _result.add(_item);
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
