package com.palweb.reservasoffline;

import android.database.Cursor;
import android.os.CancellationSignal;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
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
public final class ReservationDao_Impl implements ReservationDao {
  private final RoomDatabase __db;

  private final EntityInsertionAdapter<ReservationEntity> __insertionAdapterOfReservationEntity;

  private final EntityInsertionAdapter<ReservationItemEntity> __insertionAdapterOfReservationItemEntity;

  private final SharedSQLiteStatement __preparedStmtOfClearItems;

  private final SharedSQLiteStatement __preparedStmtOfClear;

  private final SharedSQLiteStatement __preparedStmtOfClearAllItems;

  public ReservationDao_Impl(@NonNull final RoomDatabase __db) {
    this.__db = __db;
    this.__insertionAdapterOfReservationEntity = new EntityInsertionAdapter<ReservationEntity>(__db) {
      @Override
      @NonNull
      protected String createQuery() {
        return "INSERT OR REPLACE INTO `reservations` (`id`,`localUuid`,`remoteId`,`clientName`,`clientPhone`,`clientAddress`,`clientRemoteId`,`fechaReservaEpoch`,`notes`,`metodoPago`,`canalOrigen`,`estadoPago`,`estadoReserva`,`abono`,`total`,`costoMensajeria`,`sinExistencia`,`updatedAtEpoch`,`serverUpdatedAtEpoch`,`syncAttempts`,`syncError`,`needsSync`) VALUES (nullif(?, 0),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
      }

      @Override
      protected void bind(@NonNull final SupportSQLiteStatement statement,
          @NonNull final ReservationEntity entity) {
        statement.bindLong(1, entity.getId());
        if (entity.getLocalUuid() == null) {
          statement.bindNull(2);
        } else {
          statement.bindString(2, entity.getLocalUuid());
        }
        if (entity.getRemoteId() == null) {
          statement.bindNull(3);
        } else {
          statement.bindLong(3, entity.getRemoteId());
        }
        if (entity.getClientName() == null) {
          statement.bindNull(4);
        } else {
          statement.bindString(4, entity.getClientName());
        }
        if (entity.getClientPhone() == null) {
          statement.bindNull(5);
        } else {
          statement.bindString(5, entity.getClientPhone());
        }
        if (entity.getClientAddress() == null) {
          statement.bindNull(6);
        } else {
          statement.bindString(6, entity.getClientAddress());
        }
        if (entity.getClientRemoteId() == null) {
          statement.bindNull(7);
        } else {
          statement.bindLong(7, entity.getClientRemoteId());
        }
        statement.bindLong(8, entity.getFechaReservaEpoch());
        if (entity.getNotes() == null) {
          statement.bindNull(9);
        } else {
          statement.bindString(9, entity.getNotes());
        }
        if (entity.getMetodoPago() == null) {
          statement.bindNull(10);
        } else {
          statement.bindString(10, entity.getMetodoPago());
        }
        if (entity.getCanalOrigen() == null) {
          statement.bindNull(11);
        } else {
          statement.bindString(11, entity.getCanalOrigen());
        }
        if (entity.getEstadoPago() == null) {
          statement.bindNull(12);
        } else {
          statement.bindString(12, entity.getEstadoPago());
        }
        if (entity.getEstadoReserva() == null) {
          statement.bindNull(13);
        } else {
          statement.bindString(13, entity.getEstadoReserva());
        }
        statement.bindDouble(14, entity.getAbono());
        statement.bindDouble(15, entity.getTotal());
        statement.bindDouble(16, entity.getCostoMensajeria());
        statement.bindLong(17, entity.getSinExistencia());
        statement.bindLong(18, entity.getUpdatedAtEpoch());
        statement.bindLong(19, entity.getServerUpdatedAtEpoch());
        statement.bindLong(20, entity.getSyncAttempts());
        if (entity.getSyncError() == null) {
          statement.bindNull(21);
        } else {
          statement.bindString(21, entity.getSyncError());
        }
        statement.bindLong(22, entity.getNeedsSync());
      }
    };
    this.__insertionAdapterOfReservationItemEntity = new EntityInsertionAdapter<ReservationItemEntity>(__db) {
      @Override
      @NonNull
      protected String createQuery() {
        return "INSERT OR REPLACE INTO `reservation_items` (`id`,`reservationUuid`,`productCode`,`productName`,`category`,`qty`,`price`,`stockSnapshot`,`esServicio`) VALUES (nullif(?, 0),?,?,?,?,?,?,?,?)";
      }

      @Override
      protected void bind(@NonNull final SupportSQLiteStatement statement,
          @NonNull final ReservationItemEntity entity) {
        statement.bindLong(1, entity.getId());
        if (entity.getReservationUuid() == null) {
          statement.bindNull(2);
        } else {
          statement.bindString(2, entity.getReservationUuid());
        }
        if (entity.getProductCode() == null) {
          statement.bindNull(3);
        } else {
          statement.bindString(3, entity.getProductCode());
        }
        if (entity.getProductName() == null) {
          statement.bindNull(4);
        } else {
          statement.bindString(4, entity.getProductName());
        }
        if (entity.getCategory() == null) {
          statement.bindNull(5);
        } else {
          statement.bindString(5, entity.getCategory());
        }
        statement.bindDouble(6, entity.getQty());
        statement.bindDouble(7, entity.getPrice());
        statement.bindDouble(8, entity.getStockSnapshot());
        statement.bindLong(9, entity.getEsServicio());
      }
    };
    this.__preparedStmtOfClearItems = new SharedSQLiteStatement(__db) {
      @Override
      @NonNull
      public String createQuery() {
        final String _query = "DELETE FROM reservation_items WHERE reservationUuid = ?";
        return _query;
      }
    };
    this.__preparedStmtOfClear = new SharedSQLiteStatement(__db) {
      @Override
      @NonNull
      public String createQuery() {
        final String _query = "DELETE FROM reservations";
        return _query;
      }
    };
    this.__preparedStmtOfClearAllItems = new SharedSQLiteStatement(__db) {
      @Override
      @NonNull
      public String createQuery() {
        final String _query = "DELETE FROM reservation_items";
        return _query;
      }
    };
  }

  @Override
  public Object upsert(final ReservationEntity item, final Continuation<? super Long> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Long>() {
      @Override
      @NonNull
      public Long call() throws Exception {
        __db.beginTransaction();
        try {
          final Long _result = __insertionAdapterOfReservationEntity.insertAndReturnId(item);
          __db.setTransactionSuccessful();
          return _result;
        } finally {
          __db.endTransaction();
        }
      }
    }, $completion);
  }

  @Override
  public Object upsertAll(final List<ReservationEntity> items,
      final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        __db.beginTransaction();
        try {
          __insertionAdapterOfReservationEntity.insert(items);
          __db.setTransactionSuccessful();
          return Unit.INSTANCE;
        } finally {
          __db.endTransaction();
        }
      }
    }, $completion);
  }

  @Override
  public Object insertItems(final List<ReservationItemEntity> items,
      final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        __db.beginTransaction();
        try {
          __insertionAdapterOfReservationItemEntity.insert(items);
          __db.setTransactionSuccessful();
          return Unit.INSTANCE;
        } finally {
          __db.endTransaction();
        }
      }
    }, $completion);
  }

  @Override
  public Object clearItems(final String reservationUuid,
      final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        final SupportSQLiteStatement _stmt = __preparedStmtOfClearItems.acquire();
        int _argIndex = 1;
        if (reservationUuid == null) {
          _stmt.bindNull(_argIndex);
        } else {
          _stmt.bindString(_argIndex, reservationUuid);
        }
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
          __preparedStmtOfClearItems.release(_stmt);
        }
      }
    }, $completion);
  }

  @Override
  public Object clear(final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        final SupportSQLiteStatement _stmt = __preparedStmtOfClear.acquire();
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
          __preparedStmtOfClear.release(_stmt);
        }
      }
    }, $completion);
  }

  @Override
  public Object clearAllItems(final Continuation<? super Unit> $completion) {
    return CoroutinesRoom.execute(__db, true, new Callable<Unit>() {
      @Override
      @NonNull
      public Unit call() throws Exception {
        final SupportSQLiteStatement _stmt = __preparedStmtOfClearAllItems.acquire();
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
          __preparedStmtOfClearAllItems.release(_stmt);
        }
      }
    }, $completion);
  }

  @Override
  public Flow<List<ReservationEntity>> observeAll() {
    final String _sql = "SELECT * FROM reservations ORDER BY fechaReservaEpoch ASC";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 0);
    return CoroutinesRoom.createFlow(__db, false, new String[] {"reservations"}, new Callable<List<ReservationEntity>>() {
      @Override
      @NonNull
      public List<ReservationEntity> call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfLocalUuid = CursorUtil.getColumnIndexOrThrow(_cursor, "localUuid");
          final int _cursorIndexOfRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "remoteId");
          final int _cursorIndexOfClientName = CursorUtil.getColumnIndexOrThrow(_cursor, "clientName");
          final int _cursorIndexOfClientPhone = CursorUtil.getColumnIndexOrThrow(_cursor, "clientPhone");
          final int _cursorIndexOfClientAddress = CursorUtil.getColumnIndexOrThrow(_cursor, "clientAddress");
          final int _cursorIndexOfClientRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "clientRemoteId");
          final int _cursorIndexOfFechaReservaEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "fechaReservaEpoch");
          final int _cursorIndexOfNotes = CursorUtil.getColumnIndexOrThrow(_cursor, "notes");
          final int _cursorIndexOfMetodoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "metodoPago");
          final int _cursorIndexOfCanalOrigen = CursorUtil.getColumnIndexOrThrow(_cursor, "canalOrigen");
          final int _cursorIndexOfEstadoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoPago");
          final int _cursorIndexOfEstadoReserva = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoReserva");
          final int _cursorIndexOfAbono = CursorUtil.getColumnIndexOrThrow(_cursor, "abono");
          final int _cursorIndexOfTotal = CursorUtil.getColumnIndexOrThrow(_cursor, "total");
          final int _cursorIndexOfCostoMensajeria = CursorUtil.getColumnIndexOrThrow(_cursor, "costoMensajeria");
          final int _cursorIndexOfSinExistencia = CursorUtil.getColumnIndexOrThrow(_cursor, "sinExistencia");
          final int _cursorIndexOfUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "updatedAtEpoch");
          final int _cursorIndexOfServerUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "serverUpdatedAtEpoch");
          final int _cursorIndexOfSyncAttempts = CursorUtil.getColumnIndexOrThrow(_cursor, "syncAttempts");
          final int _cursorIndexOfSyncError = CursorUtil.getColumnIndexOrThrow(_cursor, "syncError");
          final int _cursorIndexOfNeedsSync = CursorUtil.getColumnIndexOrThrow(_cursor, "needsSync");
          final List<ReservationEntity> _result = new ArrayList<ReservationEntity>(_cursor.getCount());
          while (_cursor.moveToNext()) {
            final ReservationEntity _item;
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpLocalUuid;
            if (_cursor.isNull(_cursorIndexOfLocalUuid)) {
              _tmpLocalUuid = null;
            } else {
              _tmpLocalUuid = _cursor.getString(_cursorIndexOfLocalUuid);
            }
            final Long _tmpRemoteId;
            if (_cursor.isNull(_cursorIndexOfRemoteId)) {
              _tmpRemoteId = null;
            } else {
              _tmpRemoteId = _cursor.getLong(_cursorIndexOfRemoteId);
            }
            final String _tmpClientName;
            if (_cursor.isNull(_cursorIndexOfClientName)) {
              _tmpClientName = null;
            } else {
              _tmpClientName = _cursor.getString(_cursorIndexOfClientName);
            }
            final String _tmpClientPhone;
            if (_cursor.isNull(_cursorIndexOfClientPhone)) {
              _tmpClientPhone = null;
            } else {
              _tmpClientPhone = _cursor.getString(_cursorIndexOfClientPhone);
            }
            final String _tmpClientAddress;
            if (_cursor.isNull(_cursorIndexOfClientAddress)) {
              _tmpClientAddress = null;
            } else {
              _tmpClientAddress = _cursor.getString(_cursorIndexOfClientAddress);
            }
            final Long _tmpClientRemoteId;
            if (_cursor.isNull(_cursorIndexOfClientRemoteId)) {
              _tmpClientRemoteId = null;
            } else {
              _tmpClientRemoteId = _cursor.getLong(_cursorIndexOfClientRemoteId);
            }
            final long _tmpFechaReservaEpoch;
            _tmpFechaReservaEpoch = _cursor.getLong(_cursorIndexOfFechaReservaEpoch);
            final String _tmpNotes;
            if (_cursor.isNull(_cursorIndexOfNotes)) {
              _tmpNotes = null;
            } else {
              _tmpNotes = _cursor.getString(_cursorIndexOfNotes);
            }
            final String _tmpMetodoPago;
            if (_cursor.isNull(_cursorIndexOfMetodoPago)) {
              _tmpMetodoPago = null;
            } else {
              _tmpMetodoPago = _cursor.getString(_cursorIndexOfMetodoPago);
            }
            final String _tmpCanalOrigen;
            if (_cursor.isNull(_cursorIndexOfCanalOrigen)) {
              _tmpCanalOrigen = null;
            } else {
              _tmpCanalOrigen = _cursor.getString(_cursorIndexOfCanalOrigen);
            }
            final String _tmpEstadoPago;
            if (_cursor.isNull(_cursorIndexOfEstadoPago)) {
              _tmpEstadoPago = null;
            } else {
              _tmpEstadoPago = _cursor.getString(_cursorIndexOfEstadoPago);
            }
            final String _tmpEstadoReserva;
            if (_cursor.isNull(_cursorIndexOfEstadoReserva)) {
              _tmpEstadoReserva = null;
            } else {
              _tmpEstadoReserva = _cursor.getString(_cursorIndexOfEstadoReserva);
            }
            final double _tmpAbono;
            _tmpAbono = _cursor.getDouble(_cursorIndexOfAbono);
            final double _tmpTotal;
            _tmpTotal = _cursor.getDouble(_cursorIndexOfTotal);
            final double _tmpCostoMensajeria;
            _tmpCostoMensajeria = _cursor.getDouble(_cursorIndexOfCostoMensajeria);
            final int _tmpSinExistencia;
            _tmpSinExistencia = _cursor.getInt(_cursorIndexOfSinExistencia);
            final long _tmpUpdatedAtEpoch;
            _tmpUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfUpdatedAtEpoch);
            final long _tmpServerUpdatedAtEpoch;
            _tmpServerUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfServerUpdatedAtEpoch);
            final int _tmpSyncAttempts;
            _tmpSyncAttempts = _cursor.getInt(_cursorIndexOfSyncAttempts);
            final String _tmpSyncError;
            if (_cursor.isNull(_cursorIndexOfSyncError)) {
              _tmpSyncError = null;
            } else {
              _tmpSyncError = _cursor.getString(_cursorIndexOfSyncError);
            }
            final int _tmpNeedsSync;
            _tmpNeedsSync = _cursor.getInt(_cursorIndexOfNeedsSync);
            _item = new ReservationEntity(_tmpId,_tmpLocalUuid,_tmpRemoteId,_tmpClientName,_tmpClientPhone,_tmpClientAddress,_tmpClientRemoteId,_tmpFechaReservaEpoch,_tmpNotes,_tmpMetodoPago,_tmpCanalOrigen,_tmpEstadoPago,_tmpEstadoReserva,_tmpAbono,_tmpTotal,_tmpCostoMensajeria,_tmpSinExistencia,_tmpUpdatedAtEpoch,_tmpServerUpdatedAtEpoch,_tmpSyncAttempts,_tmpSyncError,_tmpNeedsSync);
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

  @Override
  public Object byLocalUuid(final String uuid,
      final Continuation<? super ReservationEntity> $completion) {
    final String _sql = "SELECT * FROM reservations WHERE localUuid = ? LIMIT 1";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 1);
    int _argIndex = 1;
    if (uuid == null) {
      _statement.bindNull(_argIndex);
    } else {
      _statement.bindString(_argIndex, uuid);
    }
    final CancellationSignal _cancellationSignal = DBUtil.createCancellationSignal();
    return CoroutinesRoom.execute(__db, false, _cancellationSignal, new Callable<ReservationEntity>() {
      @Override
      @Nullable
      public ReservationEntity call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfLocalUuid = CursorUtil.getColumnIndexOrThrow(_cursor, "localUuid");
          final int _cursorIndexOfRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "remoteId");
          final int _cursorIndexOfClientName = CursorUtil.getColumnIndexOrThrow(_cursor, "clientName");
          final int _cursorIndexOfClientPhone = CursorUtil.getColumnIndexOrThrow(_cursor, "clientPhone");
          final int _cursorIndexOfClientAddress = CursorUtil.getColumnIndexOrThrow(_cursor, "clientAddress");
          final int _cursorIndexOfClientRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "clientRemoteId");
          final int _cursorIndexOfFechaReservaEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "fechaReservaEpoch");
          final int _cursorIndexOfNotes = CursorUtil.getColumnIndexOrThrow(_cursor, "notes");
          final int _cursorIndexOfMetodoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "metodoPago");
          final int _cursorIndexOfCanalOrigen = CursorUtil.getColumnIndexOrThrow(_cursor, "canalOrigen");
          final int _cursorIndexOfEstadoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoPago");
          final int _cursorIndexOfEstadoReserva = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoReserva");
          final int _cursorIndexOfAbono = CursorUtil.getColumnIndexOrThrow(_cursor, "abono");
          final int _cursorIndexOfTotal = CursorUtil.getColumnIndexOrThrow(_cursor, "total");
          final int _cursorIndexOfCostoMensajeria = CursorUtil.getColumnIndexOrThrow(_cursor, "costoMensajeria");
          final int _cursorIndexOfSinExistencia = CursorUtil.getColumnIndexOrThrow(_cursor, "sinExistencia");
          final int _cursorIndexOfUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "updatedAtEpoch");
          final int _cursorIndexOfServerUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "serverUpdatedAtEpoch");
          final int _cursorIndexOfSyncAttempts = CursorUtil.getColumnIndexOrThrow(_cursor, "syncAttempts");
          final int _cursorIndexOfSyncError = CursorUtil.getColumnIndexOrThrow(_cursor, "syncError");
          final int _cursorIndexOfNeedsSync = CursorUtil.getColumnIndexOrThrow(_cursor, "needsSync");
          final ReservationEntity _result;
          if (_cursor.moveToFirst()) {
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpLocalUuid;
            if (_cursor.isNull(_cursorIndexOfLocalUuid)) {
              _tmpLocalUuid = null;
            } else {
              _tmpLocalUuid = _cursor.getString(_cursorIndexOfLocalUuid);
            }
            final Long _tmpRemoteId;
            if (_cursor.isNull(_cursorIndexOfRemoteId)) {
              _tmpRemoteId = null;
            } else {
              _tmpRemoteId = _cursor.getLong(_cursorIndexOfRemoteId);
            }
            final String _tmpClientName;
            if (_cursor.isNull(_cursorIndexOfClientName)) {
              _tmpClientName = null;
            } else {
              _tmpClientName = _cursor.getString(_cursorIndexOfClientName);
            }
            final String _tmpClientPhone;
            if (_cursor.isNull(_cursorIndexOfClientPhone)) {
              _tmpClientPhone = null;
            } else {
              _tmpClientPhone = _cursor.getString(_cursorIndexOfClientPhone);
            }
            final String _tmpClientAddress;
            if (_cursor.isNull(_cursorIndexOfClientAddress)) {
              _tmpClientAddress = null;
            } else {
              _tmpClientAddress = _cursor.getString(_cursorIndexOfClientAddress);
            }
            final Long _tmpClientRemoteId;
            if (_cursor.isNull(_cursorIndexOfClientRemoteId)) {
              _tmpClientRemoteId = null;
            } else {
              _tmpClientRemoteId = _cursor.getLong(_cursorIndexOfClientRemoteId);
            }
            final long _tmpFechaReservaEpoch;
            _tmpFechaReservaEpoch = _cursor.getLong(_cursorIndexOfFechaReservaEpoch);
            final String _tmpNotes;
            if (_cursor.isNull(_cursorIndexOfNotes)) {
              _tmpNotes = null;
            } else {
              _tmpNotes = _cursor.getString(_cursorIndexOfNotes);
            }
            final String _tmpMetodoPago;
            if (_cursor.isNull(_cursorIndexOfMetodoPago)) {
              _tmpMetodoPago = null;
            } else {
              _tmpMetodoPago = _cursor.getString(_cursorIndexOfMetodoPago);
            }
            final String _tmpCanalOrigen;
            if (_cursor.isNull(_cursorIndexOfCanalOrigen)) {
              _tmpCanalOrigen = null;
            } else {
              _tmpCanalOrigen = _cursor.getString(_cursorIndexOfCanalOrigen);
            }
            final String _tmpEstadoPago;
            if (_cursor.isNull(_cursorIndexOfEstadoPago)) {
              _tmpEstadoPago = null;
            } else {
              _tmpEstadoPago = _cursor.getString(_cursorIndexOfEstadoPago);
            }
            final String _tmpEstadoReserva;
            if (_cursor.isNull(_cursorIndexOfEstadoReserva)) {
              _tmpEstadoReserva = null;
            } else {
              _tmpEstadoReserva = _cursor.getString(_cursorIndexOfEstadoReserva);
            }
            final double _tmpAbono;
            _tmpAbono = _cursor.getDouble(_cursorIndexOfAbono);
            final double _tmpTotal;
            _tmpTotal = _cursor.getDouble(_cursorIndexOfTotal);
            final double _tmpCostoMensajeria;
            _tmpCostoMensajeria = _cursor.getDouble(_cursorIndexOfCostoMensajeria);
            final int _tmpSinExistencia;
            _tmpSinExistencia = _cursor.getInt(_cursorIndexOfSinExistencia);
            final long _tmpUpdatedAtEpoch;
            _tmpUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfUpdatedAtEpoch);
            final long _tmpServerUpdatedAtEpoch;
            _tmpServerUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfServerUpdatedAtEpoch);
            final int _tmpSyncAttempts;
            _tmpSyncAttempts = _cursor.getInt(_cursorIndexOfSyncAttempts);
            final String _tmpSyncError;
            if (_cursor.isNull(_cursorIndexOfSyncError)) {
              _tmpSyncError = null;
            } else {
              _tmpSyncError = _cursor.getString(_cursorIndexOfSyncError);
            }
            final int _tmpNeedsSync;
            _tmpNeedsSync = _cursor.getInt(_cursorIndexOfNeedsSync);
            _result = new ReservationEntity(_tmpId,_tmpLocalUuid,_tmpRemoteId,_tmpClientName,_tmpClientPhone,_tmpClientAddress,_tmpClientRemoteId,_tmpFechaReservaEpoch,_tmpNotes,_tmpMetodoPago,_tmpCanalOrigen,_tmpEstadoPago,_tmpEstadoReserva,_tmpAbono,_tmpTotal,_tmpCostoMensajeria,_tmpSinExistencia,_tmpUpdatedAtEpoch,_tmpServerUpdatedAtEpoch,_tmpSyncAttempts,_tmpSyncError,_tmpNeedsSync);
          } else {
            _result = null;
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
  public Object byRemoteId(final long remoteId,
      final Continuation<? super ReservationEntity> $completion) {
    final String _sql = "SELECT * FROM reservations WHERE remoteId = ? LIMIT 1";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 1);
    int _argIndex = 1;
    _statement.bindLong(_argIndex, remoteId);
    final CancellationSignal _cancellationSignal = DBUtil.createCancellationSignal();
    return CoroutinesRoom.execute(__db, false, _cancellationSignal, new Callable<ReservationEntity>() {
      @Override
      @Nullable
      public ReservationEntity call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfLocalUuid = CursorUtil.getColumnIndexOrThrow(_cursor, "localUuid");
          final int _cursorIndexOfRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "remoteId");
          final int _cursorIndexOfClientName = CursorUtil.getColumnIndexOrThrow(_cursor, "clientName");
          final int _cursorIndexOfClientPhone = CursorUtil.getColumnIndexOrThrow(_cursor, "clientPhone");
          final int _cursorIndexOfClientAddress = CursorUtil.getColumnIndexOrThrow(_cursor, "clientAddress");
          final int _cursorIndexOfClientRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "clientRemoteId");
          final int _cursorIndexOfFechaReservaEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "fechaReservaEpoch");
          final int _cursorIndexOfNotes = CursorUtil.getColumnIndexOrThrow(_cursor, "notes");
          final int _cursorIndexOfMetodoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "metodoPago");
          final int _cursorIndexOfCanalOrigen = CursorUtil.getColumnIndexOrThrow(_cursor, "canalOrigen");
          final int _cursorIndexOfEstadoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoPago");
          final int _cursorIndexOfEstadoReserva = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoReserva");
          final int _cursorIndexOfAbono = CursorUtil.getColumnIndexOrThrow(_cursor, "abono");
          final int _cursorIndexOfTotal = CursorUtil.getColumnIndexOrThrow(_cursor, "total");
          final int _cursorIndexOfCostoMensajeria = CursorUtil.getColumnIndexOrThrow(_cursor, "costoMensajeria");
          final int _cursorIndexOfSinExistencia = CursorUtil.getColumnIndexOrThrow(_cursor, "sinExistencia");
          final int _cursorIndexOfUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "updatedAtEpoch");
          final int _cursorIndexOfServerUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "serverUpdatedAtEpoch");
          final int _cursorIndexOfSyncAttempts = CursorUtil.getColumnIndexOrThrow(_cursor, "syncAttempts");
          final int _cursorIndexOfSyncError = CursorUtil.getColumnIndexOrThrow(_cursor, "syncError");
          final int _cursorIndexOfNeedsSync = CursorUtil.getColumnIndexOrThrow(_cursor, "needsSync");
          final ReservationEntity _result;
          if (_cursor.moveToFirst()) {
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpLocalUuid;
            if (_cursor.isNull(_cursorIndexOfLocalUuid)) {
              _tmpLocalUuid = null;
            } else {
              _tmpLocalUuid = _cursor.getString(_cursorIndexOfLocalUuid);
            }
            final Long _tmpRemoteId;
            if (_cursor.isNull(_cursorIndexOfRemoteId)) {
              _tmpRemoteId = null;
            } else {
              _tmpRemoteId = _cursor.getLong(_cursorIndexOfRemoteId);
            }
            final String _tmpClientName;
            if (_cursor.isNull(_cursorIndexOfClientName)) {
              _tmpClientName = null;
            } else {
              _tmpClientName = _cursor.getString(_cursorIndexOfClientName);
            }
            final String _tmpClientPhone;
            if (_cursor.isNull(_cursorIndexOfClientPhone)) {
              _tmpClientPhone = null;
            } else {
              _tmpClientPhone = _cursor.getString(_cursorIndexOfClientPhone);
            }
            final String _tmpClientAddress;
            if (_cursor.isNull(_cursorIndexOfClientAddress)) {
              _tmpClientAddress = null;
            } else {
              _tmpClientAddress = _cursor.getString(_cursorIndexOfClientAddress);
            }
            final Long _tmpClientRemoteId;
            if (_cursor.isNull(_cursorIndexOfClientRemoteId)) {
              _tmpClientRemoteId = null;
            } else {
              _tmpClientRemoteId = _cursor.getLong(_cursorIndexOfClientRemoteId);
            }
            final long _tmpFechaReservaEpoch;
            _tmpFechaReservaEpoch = _cursor.getLong(_cursorIndexOfFechaReservaEpoch);
            final String _tmpNotes;
            if (_cursor.isNull(_cursorIndexOfNotes)) {
              _tmpNotes = null;
            } else {
              _tmpNotes = _cursor.getString(_cursorIndexOfNotes);
            }
            final String _tmpMetodoPago;
            if (_cursor.isNull(_cursorIndexOfMetodoPago)) {
              _tmpMetodoPago = null;
            } else {
              _tmpMetodoPago = _cursor.getString(_cursorIndexOfMetodoPago);
            }
            final String _tmpCanalOrigen;
            if (_cursor.isNull(_cursorIndexOfCanalOrigen)) {
              _tmpCanalOrigen = null;
            } else {
              _tmpCanalOrigen = _cursor.getString(_cursorIndexOfCanalOrigen);
            }
            final String _tmpEstadoPago;
            if (_cursor.isNull(_cursorIndexOfEstadoPago)) {
              _tmpEstadoPago = null;
            } else {
              _tmpEstadoPago = _cursor.getString(_cursorIndexOfEstadoPago);
            }
            final String _tmpEstadoReserva;
            if (_cursor.isNull(_cursorIndexOfEstadoReserva)) {
              _tmpEstadoReserva = null;
            } else {
              _tmpEstadoReserva = _cursor.getString(_cursorIndexOfEstadoReserva);
            }
            final double _tmpAbono;
            _tmpAbono = _cursor.getDouble(_cursorIndexOfAbono);
            final double _tmpTotal;
            _tmpTotal = _cursor.getDouble(_cursorIndexOfTotal);
            final double _tmpCostoMensajeria;
            _tmpCostoMensajeria = _cursor.getDouble(_cursorIndexOfCostoMensajeria);
            final int _tmpSinExistencia;
            _tmpSinExistencia = _cursor.getInt(_cursorIndexOfSinExistencia);
            final long _tmpUpdatedAtEpoch;
            _tmpUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfUpdatedAtEpoch);
            final long _tmpServerUpdatedAtEpoch;
            _tmpServerUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfServerUpdatedAtEpoch);
            final int _tmpSyncAttempts;
            _tmpSyncAttempts = _cursor.getInt(_cursorIndexOfSyncAttempts);
            final String _tmpSyncError;
            if (_cursor.isNull(_cursorIndexOfSyncError)) {
              _tmpSyncError = null;
            } else {
              _tmpSyncError = _cursor.getString(_cursorIndexOfSyncError);
            }
            final int _tmpNeedsSync;
            _tmpNeedsSync = _cursor.getInt(_cursorIndexOfNeedsSync);
            _result = new ReservationEntity(_tmpId,_tmpLocalUuid,_tmpRemoteId,_tmpClientName,_tmpClientPhone,_tmpClientAddress,_tmpClientRemoteId,_tmpFechaReservaEpoch,_tmpNotes,_tmpMetodoPago,_tmpCanalOrigen,_tmpEstadoPago,_tmpEstadoReserva,_tmpAbono,_tmpTotal,_tmpCostoMensajeria,_tmpSinExistencia,_tmpUpdatedAtEpoch,_tmpServerUpdatedAtEpoch,_tmpSyncAttempts,_tmpSyncError,_tmpNeedsSync);
          } else {
            _result = null;
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
  public Object itemsByUuid(final String uuid,
      final Continuation<? super List<ReservationItemEntity>> $completion) {
    final String _sql = "SELECT * FROM reservation_items WHERE reservationUuid = ?";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 1);
    int _argIndex = 1;
    if (uuid == null) {
      _statement.bindNull(_argIndex);
    } else {
      _statement.bindString(_argIndex, uuid);
    }
    final CancellationSignal _cancellationSignal = DBUtil.createCancellationSignal();
    return CoroutinesRoom.execute(__db, false, _cancellationSignal, new Callable<List<ReservationItemEntity>>() {
      @Override
      @NonNull
      public List<ReservationItemEntity> call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfReservationUuid = CursorUtil.getColumnIndexOrThrow(_cursor, "reservationUuid");
          final int _cursorIndexOfProductCode = CursorUtil.getColumnIndexOrThrow(_cursor, "productCode");
          final int _cursorIndexOfProductName = CursorUtil.getColumnIndexOrThrow(_cursor, "productName");
          final int _cursorIndexOfCategory = CursorUtil.getColumnIndexOrThrow(_cursor, "category");
          final int _cursorIndexOfQty = CursorUtil.getColumnIndexOrThrow(_cursor, "qty");
          final int _cursorIndexOfPrice = CursorUtil.getColumnIndexOrThrow(_cursor, "price");
          final int _cursorIndexOfStockSnapshot = CursorUtil.getColumnIndexOrThrow(_cursor, "stockSnapshot");
          final int _cursorIndexOfEsServicio = CursorUtil.getColumnIndexOrThrow(_cursor, "esServicio");
          final List<ReservationItemEntity> _result = new ArrayList<ReservationItemEntity>(_cursor.getCount());
          while (_cursor.moveToNext()) {
            final ReservationItemEntity _item;
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpReservationUuid;
            if (_cursor.isNull(_cursorIndexOfReservationUuid)) {
              _tmpReservationUuid = null;
            } else {
              _tmpReservationUuid = _cursor.getString(_cursorIndexOfReservationUuid);
            }
            final String _tmpProductCode;
            if (_cursor.isNull(_cursorIndexOfProductCode)) {
              _tmpProductCode = null;
            } else {
              _tmpProductCode = _cursor.getString(_cursorIndexOfProductCode);
            }
            final String _tmpProductName;
            if (_cursor.isNull(_cursorIndexOfProductName)) {
              _tmpProductName = null;
            } else {
              _tmpProductName = _cursor.getString(_cursorIndexOfProductName);
            }
            final String _tmpCategory;
            if (_cursor.isNull(_cursorIndexOfCategory)) {
              _tmpCategory = null;
            } else {
              _tmpCategory = _cursor.getString(_cursorIndexOfCategory);
            }
            final double _tmpQty;
            _tmpQty = _cursor.getDouble(_cursorIndexOfQty);
            final double _tmpPrice;
            _tmpPrice = _cursor.getDouble(_cursorIndexOfPrice);
            final double _tmpStockSnapshot;
            _tmpStockSnapshot = _cursor.getDouble(_cursorIndexOfStockSnapshot);
            final int _tmpEsServicio;
            _tmpEsServicio = _cursor.getInt(_cursorIndexOfEsServicio);
            _item = new ReservationItemEntity(_tmpId,_tmpReservationUuid,_tmpProductCode,_tmpProductName,_tmpCategory,_tmpQty,_tmpPrice,_tmpStockSnapshot,_tmpEsServicio);
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
  public Flow<ReservationEntity> observeByUuid(final String uuid) {
    final String _sql = "SELECT * FROM reservations WHERE localUuid = ? LIMIT 1";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 1);
    int _argIndex = 1;
    if (uuid == null) {
      _statement.bindNull(_argIndex);
    } else {
      _statement.bindString(_argIndex, uuid);
    }
    return CoroutinesRoom.createFlow(__db, false, new String[] {"reservations"}, new Callable<ReservationEntity>() {
      @Override
      @Nullable
      public ReservationEntity call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfLocalUuid = CursorUtil.getColumnIndexOrThrow(_cursor, "localUuid");
          final int _cursorIndexOfRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "remoteId");
          final int _cursorIndexOfClientName = CursorUtil.getColumnIndexOrThrow(_cursor, "clientName");
          final int _cursorIndexOfClientPhone = CursorUtil.getColumnIndexOrThrow(_cursor, "clientPhone");
          final int _cursorIndexOfClientAddress = CursorUtil.getColumnIndexOrThrow(_cursor, "clientAddress");
          final int _cursorIndexOfClientRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "clientRemoteId");
          final int _cursorIndexOfFechaReservaEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "fechaReservaEpoch");
          final int _cursorIndexOfNotes = CursorUtil.getColumnIndexOrThrow(_cursor, "notes");
          final int _cursorIndexOfMetodoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "metodoPago");
          final int _cursorIndexOfCanalOrigen = CursorUtil.getColumnIndexOrThrow(_cursor, "canalOrigen");
          final int _cursorIndexOfEstadoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoPago");
          final int _cursorIndexOfEstadoReserva = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoReserva");
          final int _cursorIndexOfAbono = CursorUtil.getColumnIndexOrThrow(_cursor, "abono");
          final int _cursorIndexOfTotal = CursorUtil.getColumnIndexOrThrow(_cursor, "total");
          final int _cursorIndexOfCostoMensajeria = CursorUtil.getColumnIndexOrThrow(_cursor, "costoMensajeria");
          final int _cursorIndexOfSinExistencia = CursorUtil.getColumnIndexOrThrow(_cursor, "sinExistencia");
          final int _cursorIndexOfUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "updatedAtEpoch");
          final int _cursorIndexOfServerUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "serverUpdatedAtEpoch");
          final int _cursorIndexOfSyncAttempts = CursorUtil.getColumnIndexOrThrow(_cursor, "syncAttempts");
          final int _cursorIndexOfSyncError = CursorUtil.getColumnIndexOrThrow(_cursor, "syncError");
          final int _cursorIndexOfNeedsSync = CursorUtil.getColumnIndexOrThrow(_cursor, "needsSync");
          final ReservationEntity _result;
          if (_cursor.moveToFirst()) {
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpLocalUuid;
            if (_cursor.isNull(_cursorIndexOfLocalUuid)) {
              _tmpLocalUuid = null;
            } else {
              _tmpLocalUuid = _cursor.getString(_cursorIndexOfLocalUuid);
            }
            final Long _tmpRemoteId;
            if (_cursor.isNull(_cursorIndexOfRemoteId)) {
              _tmpRemoteId = null;
            } else {
              _tmpRemoteId = _cursor.getLong(_cursorIndexOfRemoteId);
            }
            final String _tmpClientName;
            if (_cursor.isNull(_cursorIndexOfClientName)) {
              _tmpClientName = null;
            } else {
              _tmpClientName = _cursor.getString(_cursorIndexOfClientName);
            }
            final String _tmpClientPhone;
            if (_cursor.isNull(_cursorIndexOfClientPhone)) {
              _tmpClientPhone = null;
            } else {
              _tmpClientPhone = _cursor.getString(_cursorIndexOfClientPhone);
            }
            final String _tmpClientAddress;
            if (_cursor.isNull(_cursorIndexOfClientAddress)) {
              _tmpClientAddress = null;
            } else {
              _tmpClientAddress = _cursor.getString(_cursorIndexOfClientAddress);
            }
            final Long _tmpClientRemoteId;
            if (_cursor.isNull(_cursorIndexOfClientRemoteId)) {
              _tmpClientRemoteId = null;
            } else {
              _tmpClientRemoteId = _cursor.getLong(_cursorIndexOfClientRemoteId);
            }
            final long _tmpFechaReservaEpoch;
            _tmpFechaReservaEpoch = _cursor.getLong(_cursorIndexOfFechaReservaEpoch);
            final String _tmpNotes;
            if (_cursor.isNull(_cursorIndexOfNotes)) {
              _tmpNotes = null;
            } else {
              _tmpNotes = _cursor.getString(_cursorIndexOfNotes);
            }
            final String _tmpMetodoPago;
            if (_cursor.isNull(_cursorIndexOfMetodoPago)) {
              _tmpMetodoPago = null;
            } else {
              _tmpMetodoPago = _cursor.getString(_cursorIndexOfMetodoPago);
            }
            final String _tmpCanalOrigen;
            if (_cursor.isNull(_cursorIndexOfCanalOrigen)) {
              _tmpCanalOrigen = null;
            } else {
              _tmpCanalOrigen = _cursor.getString(_cursorIndexOfCanalOrigen);
            }
            final String _tmpEstadoPago;
            if (_cursor.isNull(_cursorIndexOfEstadoPago)) {
              _tmpEstadoPago = null;
            } else {
              _tmpEstadoPago = _cursor.getString(_cursorIndexOfEstadoPago);
            }
            final String _tmpEstadoReserva;
            if (_cursor.isNull(_cursorIndexOfEstadoReserva)) {
              _tmpEstadoReserva = null;
            } else {
              _tmpEstadoReserva = _cursor.getString(_cursorIndexOfEstadoReserva);
            }
            final double _tmpAbono;
            _tmpAbono = _cursor.getDouble(_cursorIndexOfAbono);
            final double _tmpTotal;
            _tmpTotal = _cursor.getDouble(_cursorIndexOfTotal);
            final double _tmpCostoMensajeria;
            _tmpCostoMensajeria = _cursor.getDouble(_cursorIndexOfCostoMensajeria);
            final int _tmpSinExistencia;
            _tmpSinExistencia = _cursor.getInt(_cursorIndexOfSinExistencia);
            final long _tmpUpdatedAtEpoch;
            _tmpUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfUpdatedAtEpoch);
            final long _tmpServerUpdatedAtEpoch;
            _tmpServerUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfServerUpdatedAtEpoch);
            final int _tmpSyncAttempts;
            _tmpSyncAttempts = _cursor.getInt(_cursorIndexOfSyncAttempts);
            final String _tmpSyncError;
            if (_cursor.isNull(_cursorIndexOfSyncError)) {
              _tmpSyncError = null;
            } else {
              _tmpSyncError = _cursor.getString(_cursorIndexOfSyncError);
            }
            final int _tmpNeedsSync;
            _tmpNeedsSync = _cursor.getInt(_cursorIndexOfNeedsSync);
            _result = new ReservationEntity(_tmpId,_tmpLocalUuid,_tmpRemoteId,_tmpClientName,_tmpClientPhone,_tmpClientAddress,_tmpClientRemoteId,_tmpFechaReservaEpoch,_tmpNotes,_tmpMetodoPago,_tmpCanalOrigen,_tmpEstadoPago,_tmpEstadoReserva,_tmpAbono,_tmpTotal,_tmpCostoMensajeria,_tmpSinExistencia,_tmpUpdatedAtEpoch,_tmpServerUpdatedAtEpoch,_tmpSyncAttempts,_tmpSyncError,_tmpNeedsSync);
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

  @Override
  public Object findByRemote(final long remoteId,
      final Continuation<? super ReservationEntity> $completion) {
    final String _sql = "SELECT * FROM reservations WHERE remoteId = ? LIMIT 1";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 1);
    int _argIndex = 1;
    _statement.bindLong(_argIndex, remoteId);
    final CancellationSignal _cancellationSignal = DBUtil.createCancellationSignal();
    return CoroutinesRoom.execute(__db, false, _cancellationSignal, new Callable<ReservationEntity>() {
      @Override
      @Nullable
      public ReservationEntity call() throws Exception {
        final Cursor _cursor = DBUtil.query(__db, _statement, false, null);
        try {
          final int _cursorIndexOfId = CursorUtil.getColumnIndexOrThrow(_cursor, "id");
          final int _cursorIndexOfLocalUuid = CursorUtil.getColumnIndexOrThrow(_cursor, "localUuid");
          final int _cursorIndexOfRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "remoteId");
          final int _cursorIndexOfClientName = CursorUtil.getColumnIndexOrThrow(_cursor, "clientName");
          final int _cursorIndexOfClientPhone = CursorUtil.getColumnIndexOrThrow(_cursor, "clientPhone");
          final int _cursorIndexOfClientAddress = CursorUtil.getColumnIndexOrThrow(_cursor, "clientAddress");
          final int _cursorIndexOfClientRemoteId = CursorUtil.getColumnIndexOrThrow(_cursor, "clientRemoteId");
          final int _cursorIndexOfFechaReservaEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "fechaReservaEpoch");
          final int _cursorIndexOfNotes = CursorUtil.getColumnIndexOrThrow(_cursor, "notes");
          final int _cursorIndexOfMetodoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "metodoPago");
          final int _cursorIndexOfCanalOrigen = CursorUtil.getColumnIndexOrThrow(_cursor, "canalOrigen");
          final int _cursorIndexOfEstadoPago = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoPago");
          final int _cursorIndexOfEstadoReserva = CursorUtil.getColumnIndexOrThrow(_cursor, "estadoReserva");
          final int _cursorIndexOfAbono = CursorUtil.getColumnIndexOrThrow(_cursor, "abono");
          final int _cursorIndexOfTotal = CursorUtil.getColumnIndexOrThrow(_cursor, "total");
          final int _cursorIndexOfCostoMensajeria = CursorUtil.getColumnIndexOrThrow(_cursor, "costoMensajeria");
          final int _cursorIndexOfSinExistencia = CursorUtil.getColumnIndexOrThrow(_cursor, "sinExistencia");
          final int _cursorIndexOfUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "updatedAtEpoch");
          final int _cursorIndexOfServerUpdatedAtEpoch = CursorUtil.getColumnIndexOrThrow(_cursor, "serverUpdatedAtEpoch");
          final int _cursorIndexOfSyncAttempts = CursorUtil.getColumnIndexOrThrow(_cursor, "syncAttempts");
          final int _cursorIndexOfSyncError = CursorUtil.getColumnIndexOrThrow(_cursor, "syncError");
          final int _cursorIndexOfNeedsSync = CursorUtil.getColumnIndexOrThrow(_cursor, "needsSync");
          final ReservationEntity _result;
          if (_cursor.moveToFirst()) {
            final long _tmpId;
            _tmpId = _cursor.getLong(_cursorIndexOfId);
            final String _tmpLocalUuid;
            if (_cursor.isNull(_cursorIndexOfLocalUuid)) {
              _tmpLocalUuid = null;
            } else {
              _tmpLocalUuid = _cursor.getString(_cursorIndexOfLocalUuid);
            }
            final Long _tmpRemoteId;
            if (_cursor.isNull(_cursorIndexOfRemoteId)) {
              _tmpRemoteId = null;
            } else {
              _tmpRemoteId = _cursor.getLong(_cursorIndexOfRemoteId);
            }
            final String _tmpClientName;
            if (_cursor.isNull(_cursorIndexOfClientName)) {
              _tmpClientName = null;
            } else {
              _tmpClientName = _cursor.getString(_cursorIndexOfClientName);
            }
            final String _tmpClientPhone;
            if (_cursor.isNull(_cursorIndexOfClientPhone)) {
              _tmpClientPhone = null;
            } else {
              _tmpClientPhone = _cursor.getString(_cursorIndexOfClientPhone);
            }
            final String _tmpClientAddress;
            if (_cursor.isNull(_cursorIndexOfClientAddress)) {
              _tmpClientAddress = null;
            } else {
              _tmpClientAddress = _cursor.getString(_cursorIndexOfClientAddress);
            }
            final Long _tmpClientRemoteId;
            if (_cursor.isNull(_cursorIndexOfClientRemoteId)) {
              _tmpClientRemoteId = null;
            } else {
              _tmpClientRemoteId = _cursor.getLong(_cursorIndexOfClientRemoteId);
            }
            final long _tmpFechaReservaEpoch;
            _tmpFechaReservaEpoch = _cursor.getLong(_cursorIndexOfFechaReservaEpoch);
            final String _tmpNotes;
            if (_cursor.isNull(_cursorIndexOfNotes)) {
              _tmpNotes = null;
            } else {
              _tmpNotes = _cursor.getString(_cursorIndexOfNotes);
            }
            final String _tmpMetodoPago;
            if (_cursor.isNull(_cursorIndexOfMetodoPago)) {
              _tmpMetodoPago = null;
            } else {
              _tmpMetodoPago = _cursor.getString(_cursorIndexOfMetodoPago);
            }
            final String _tmpCanalOrigen;
            if (_cursor.isNull(_cursorIndexOfCanalOrigen)) {
              _tmpCanalOrigen = null;
            } else {
              _tmpCanalOrigen = _cursor.getString(_cursorIndexOfCanalOrigen);
            }
            final String _tmpEstadoPago;
            if (_cursor.isNull(_cursorIndexOfEstadoPago)) {
              _tmpEstadoPago = null;
            } else {
              _tmpEstadoPago = _cursor.getString(_cursorIndexOfEstadoPago);
            }
            final String _tmpEstadoReserva;
            if (_cursor.isNull(_cursorIndexOfEstadoReserva)) {
              _tmpEstadoReserva = null;
            } else {
              _tmpEstadoReserva = _cursor.getString(_cursorIndexOfEstadoReserva);
            }
            final double _tmpAbono;
            _tmpAbono = _cursor.getDouble(_cursorIndexOfAbono);
            final double _tmpTotal;
            _tmpTotal = _cursor.getDouble(_cursorIndexOfTotal);
            final double _tmpCostoMensajeria;
            _tmpCostoMensajeria = _cursor.getDouble(_cursorIndexOfCostoMensajeria);
            final int _tmpSinExistencia;
            _tmpSinExistencia = _cursor.getInt(_cursorIndexOfSinExistencia);
            final long _tmpUpdatedAtEpoch;
            _tmpUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfUpdatedAtEpoch);
            final long _tmpServerUpdatedAtEpoch;
            _tmpServerUpdatedAtEpoch = _cursor.getLong(_cursorIndexOfServerUpdatedAtEpoch);
            final int _tmpSyncAttempts;
            _tmpSyncAttempts = _cursor.getInt(_cursorIndexOfSyncAttempts);
            final String _tmpSyncError;
            if (_cursor.isNull(_cursorIndexOfSyncError)) {
              _tmpSyncError = null;
            } else {
              _tmpSyncError = _cursor.getString(_cursorIndexOfSyncError);
            }
            final int _tmpNeedsSync;
            _tmpNeedsSync = _cursor.getInt(_cursorIndexOfNeedsSync);
            _result = new ReservationEntity(_tmpId,_tmpLocalUuid,_tmpRemoteId,_tmpClientName,_tmpClientPhone,_tmpClientAddress,_tmpClientRemoteId,_tmpFechaReservaEpoch,_tmpNotes,_tmpMetodoPago,_tmpCanalOrigen,_tmpEstadoPago,_tmpEstadoReserva,_tmpAbono,_tmpTotal,_tmpCostoMensajeria,_tmpSinExistencia,_tmpUpdatedAtEpoch,_tmpServerUpdatedAtEpoch,_tmpSyncAttempts,_tmpSyncError,_tmpNeedsSync);
          } else {
            _result = null;
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
  public Flow<Integer> countPending() {
    final String _sql = "SELECT COUNT(*) FROM reservations WHERE estadoReserva = 'PENDIENTE'";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 0);
    return CoroutinesRoom.createFlow(__db, false, new String[] {"reservations"}, new Callable<Integer>() {
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

  @Override
  public Flow<Integer> countNeedsSyncFlow() {
    final String _sql = "SELECT COUNT(*) FROM reservations WHERE needsSync = 1";
    final RoomSQLiteQuery _statement = RoomSQLiteQuery.acquire(_sql, 0);
    return CoroutinesRoom.createFlow(__db, false, new String[] {"reservations"}, new Callable<Integer>() {
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
