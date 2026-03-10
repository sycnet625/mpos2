package com.palweb.reservasoffline;

import androidx.annotation.NonNull;
import androidx.room.DatabaseConfiguration;
import androidx.room.InvalidationTracker;
import androidx.room.RoomDatabase;
import androidx.room.RoomOpenHelper;
import androidx.room.migration.AutoMigrationSpec;
import androidx.room.migration.Migration;
import androidx.room.util.DBUtil;
import androidx.room.util.TableInfo;
import androidx.sqlite.db.SupportSQLiteDatabase;
import androidx.sqlite.db.SupportSQLiteOpenHelper;
import java.lang.Class;
import java.lang.Override;
import java.lang.String;
import java.lang.SuppressWarnings;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import javax.annotation.processing.Generated;

@Generated("androidx.room.RoomProcessor")
@SuppressWarnings({"unchecked", "deprecation"})
public final class AppDatabase_Impl extends AppDatabase {
  private volatile ProductDao _productDao;

  private volatile ClientDao _clientDao;

  private volatile ReservationDao _reservationDao;

  private volatile QueueDao _queueDao;

  private volatile SyncHistoryDao _syncHistoryDao;

  @Override
  @NonNull
  protected SupportSQLiteOpenHelper createOpenHelper(@NonNull final DatabaseConfiguration config) {
    final SupportSQLiteOpenHelper.Callback _openCallback = new RoomOpenHelper(config, new RoomOpenHelper.Delegate(2) {
      @Override
      public void createAllTables(@NonNull final SupportSQLiteDatabase db) {
        db.execSQL("CREATE TABLE IF NOT EXISTS `products` (`code` TEXT NOT NULL, `name` TEXT NOT NULL, `price` REAL NOT NULL, `category` TEXT NOT NULL, `stock` REAL NOT NULL, `esServicio` INTEGER NOT NULL, `esReservable` INTEGER NOT NULL, `active` INTEGER NOT NULL, PRIMARY KEY(`code`))");
        db.execSQL("CREATE TABLE IF NOT EXISTS `clients` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `remoteId` INTEGER, `name` TEXT NOT NULL, `phone` TEXT NOT NULL, `address` TEXT NOT NULL, `category` TEXT NOT NULL, `active` INTEGER NOT NULL)");
        db.execSQL("CREATE UNIQUE INDEX IF NOT EXISTS `index_clients_remoteId` ON `clients` (`remoteId`)");
        db.execSQL("CREATE TABLE IF NOT EXISTS `reservations` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `localUuid` TEXT NOT NULL, `remoteId` INTEGER, `clientName` TEXT NOT NULL, `clientPhone` TEXT NOT NULL, `clientAddress` TEXT NOT NULL, `clientRemoteId` INTEGER, `fechaReservaEpoch` INTEGER NOT NULL, `notes` TEXT NOT NULL, `metodoPago` TEXT NOT NULL, `canalOrigen` TEXT NOT NULL, `estadoPago` TEXT NOT NULL, `estadoReserva` TEXT NOT NULL, `abono` REAL NOT NULL, `total` REAL NOT NULL, `costoMensajeria` REAL NOT NULL, `sinExistencia` INTEGER NOT NULL, `updatedAtEpoch` INTEGER NOT NULL, `serverUpdatedAtEpoch` INTEGER NOT NULL, `syncAttempts` INTEGER NOT NULL, `syncError` TEXT NOT NULL, `needsSync` INTEGER NOT NULL)");
        db.execSQL("CREATE UNIQUE INDEX IF NOT EXISTS `index_reservations_localUuid` ON `reservations` (`localUuid`)");
        db.execSQL("CREATE INDEX IF NOT EXISTS `index_reservations_remoteId` ON `reservations` (`remoteId`)");
        db.execSQL("CREATE TABLE IF NOT EXISTS `reservation_items` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `reservationUuid` TEXT NOT NULL, `productCode` TEXT NOT NULL, `productName` TEXT NOT NULL, `category` TEXT NOT NULL, `qty` REAL NOT NULL, `price` REAL NOT NULL, `stockSnapshot` REAL NOT NULL, `esServicio` INTEGER NOT NULL, FOREIGN KEY(`reservationUuid`) REFERENCES `reservations`(`localUuid`) ON UPDATE NO ACTION ON DELETE CASCADE )");
        db.execSQL("CREATE INDEX IF NOT EXISTS `index_reservation_items_reservationUuid` ON `reservation_items` (`reservationUuid`)");
        db.execSQL("CREATE TABLE IF NOT EXISTS `sync_queue` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `opType` TEXT NOT NULL, `reservationUuid` TEXT, `payloadJson` TEXT NOT NULL, `createdAtEpoch` INTEGER NOT NULL, `attempts` INTEGER NOT NULL, `nextAttemptAtEpoch` INTEGER NOT NULL, `lastError` TEXT NOT NULL)");
        db.execSQL("CREATE TABLE IF NOT EXISTS `sync_history` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `action` TEXT NOT NULL, `success` INTEGER NOT NULL, `detail` TEXT NOT NULL, `itemsTotal` INTEGER NOT NULL, `itemsOk` INTEGER NOT NULL, `createdAtEpoch` INTEGER NOT NULL)");
        db.execSQL("CREATE INDEX IF NOT EXISTS `index_sync_history_createdAtEpoch` ON `sync_history` (`createdAtEpoch`)");
        db.execSQL("CREATE TABLE IF NOT EXISTS room_master_table (id INTEGER PRIMARY KEY,identity_hash TEXT)");
        db.execSQL("INSERT OR REPLACE INTO room_master_table (id,identity_hash) VALUES(42, '29134e42c567d2ba855e67190df67db1')");
      }

      @Override
      public void dropAllTables(@NonNull final SupportSQLiteDatabase db) {
        db.execSQL("DROP TABLE IF EXISTS `products`");
        db.execSQL("DROP TABLE IF EXISTS `clients`");
        db.execSQL("DROP TABLE IF EXISTS `reservations`");
        db.execSQL("DROP TABLE IF EXISTS `reservation_items`");
        db.execSQL("DROP TABLE IF EXISTS `sync_queue`");
        db.execSQL("DROP TABLE IF EXISTS `sync_history`");
        final List<? extends RoomDatabase.Callback> _callbacks = mCallbacks;
        if (_callbacks != null) {
          for (RoomDatabase.Callback _callback : _callbacks) {
            _callback.onDestructiveMigration(db);
          }
        }
      }

      @Override
      public void onCreate(@NonNull final SupportSQLiteDatabase db) {
        final List<? extends RoomDatabase.Callback> _callbacks = mCallbacks;
        if (_callbacks != null) {
          for (RoomDatabase.Callback _callback : _callbacks) {
            _callback.onCreate(db);
          }
        }
      }

      @Override
      public void onOpen(@NonNull final SupportSQLiteDatabase db) {
        mDatabase = db;
        db.execSQL("PRAGMA foreign_keys = ON");
        internalInitInvalidationTracker(db);
        final List<? extends RoomDatabase.Callback> _callbacks = mCallbacks;
        if (_callbacks != null) {
          for (RoomDatabase.Callback _callback : _callbacks) {
            _callback.onOpen(db);
          }
        }
      }

      @Override
      public void onPreMigrate(@NonNull final SupportSQLiteDatabase db) {
        DBUtil.dropFtsSyncTriggers(db);
      }

      @Override
      public void onPostMigrate(@NonNull final SupportSQLiteDatabase db) {
      }

      @Override
      @NonNull
      public RoomOpenHelper.ValidationResult onValidateSchema(
          @NonNull final SupportSQLiteDatabase db) {
        final HashMap<String, TableInfo.Column> _columnsProducts = new HashMap<String, TableInfo.Column>(8);
        _columnsProducts.put("code", new TableInfo.Column("code", "TEXT", true, 1, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsProducts.put("name", new TableInfo.Column("name", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsProducts.put("price", new TableInfo.Column("price", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsProducts.put("category", new TableInfo.Column("category", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsProducts.put("stock", new TableInfo.Column("stock", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsProducts.put("esServicio", new TableInfo.Column("esServicio", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsProducts.put("esReservable", new TableInfo.Column("esReservable", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsProducts.put("active", new TableInfo.Column("active", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        final HashSet<TableInfo.ForeignKey> _foreignKeysProducts = new HashSet<TableInfo.ForeignKey>(0);
        final HashSet<TableInfo.Index> _indicesProducts = new HashSet<TableInfo.Index>(0);
        final TableInfo _infoProducts = new TableInfo("products", _columnsProducts, _foreignKeysProducts, _indicesProducts);
        final TableInfo _existingProducts = TableInfo.read(db, "products");
        if (!_infoProducts.equals(_existingProducts)) {
          return new RoomOpenHelper.ValidationResult(false, "products(com.palweb.reservasoffline.ProductEntity).\n"
                  + " Expected:\n" + _infoProducts + "\n"
                  + " Found:\n" + _existingProducts);
        }
        final HashMap<String, TableInfo.Column> _columnsClients = new HashMap<String, TableInfo.Column>(7);
        _columnsClients.put("id", new TableInfo.Column("id", "INTEGER", true, 1, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsClients.put("remoteId", new TableInfo.Column("remoteId", "INTEGER", false, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsClients.put("name", new TableInfo.Column("name", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsClients.put("phone", new TableInfo.Column("phone", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsClients.put("address", new TableInfo.Column("address", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsClients.put("category", new TableInfo.Column("category", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsClients.put("active", new TableInfo.Column("active", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        final HashSet<TableInfo.ForeignKey> _foreignKeysClients = new HashSet<TableInfo.ForeignKey>(0);
        final HashSet<TableInfo.Index> _indicesClients = new HashSet<TableInfo.Index>(1);
        _indicesClients.add(new TableInfo.Index("index_clients_remoteId", true, Arrays.asList("remoteId"), Arrays.asList("ASC")));
        final TableInfo _infoClients = new TableInfo("clients", _columnsClients, _foreignKeysClients, _indicesClients);
        final TableInfo _existingClients = TableInfo.read(db, "clients");
        if (!_infoClients.equals(_existingClients)) {
          return new RoomOpenHelper.ValidationResult(false, "clients(com.palweb.reservasoffline.ClientEntity).\n"
                  + " Expected:\n" + _infoClients + "\n"
                  + " Found:\n" + _existingClients);
        }
        final HashMap<String, TableInfo.Column> _columnsReservations = new HashMap<String, TableInfo.Column>(22);
        _columnsReservations.put("id", new TableInfo.Column("id", "INTEGER", true, 1, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("localUuid", new TableInfo.Column("localUuid", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("remoteId", new TableInfo.Column("remoteId", "INTEGER", false, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("clientName", new TableInfo.Column("clientName", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("clientPhone", new TableInfo.Column("clientPhone", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("clientAddress", new TableInfo.Column("clientAddress", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("clientRemoteId", new TableInfo.Column("clientRemoteId", "INTEGER", false, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("fechaReservaEpoch", new TableInfo.Column("fechaReservaEpoch", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("notes", new TableInfo.Column("notes", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("metodoPago", new TableInfo.Column("metodoPago", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("canalOrigen", new TableInfo.Column("canalOrigen", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("estadoPago", new TableInfo.Column("estadoPago", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("estadoReserva", new TableInfo.Column("estadoReserva", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("abono", new TableInfo.Column("abono", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("total", new TableInfo.Column("total", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("costoMensajeria", new TableInfo.Column("costoMensajeria", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("sinExistencia", new TableInfo.Column("sinExistencia", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("updatedAtEpoch", new TableInfo.Column("updatedAtEpoch", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("serverUpdatedAtEpoch", new TableInfo.Column("serverUpdatedAtEpoch", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("syncAttempts", new TableInfo.Column("syncAttempts", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("syncError", new TableInfo.Column("syncError", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservations.put("needsSync", new TableInfo.Column("needsSync", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        final HashSet<TableInfo.ForeignKey> _foreignKeysReservations = new HashSet<TableInfo.ForeignKey>(0);
        final HashSet<TableInfo.Index> _indicesReservations = new HashSet<TableInfo.Index>(2);
        _indicesReservations.add(new TableInfo.Index("index_reservations_localUuid", true, Arrays.asList("localUuid"), Arrays.asList("ASC")));
        _indicesReservations.add(new TableInfo.Index("index_reservations_remoteId", false, Arrays.asList("remoteId"), Arrays.asList("ASC")));
        final TableInfo _infoReservations = new TableInfo("reservations", _columnsReservations, _foreignKeysReservations, _indicesReservations);
        final TableInfo _existingReservations = TableInfo.read(db, "reservations");
        if (!_infoReservations.equals(_existingReservations)) {
          return new RoomOpenHelper.ValidationResult(false, "reservations(com.palweb.reservasoffline.ReservationEntity).\n"
                  + " Expected:\n" + _infoReservations + "\n"
                  + " Found:\n" + _existingReservations);
        }
        final HashMap<String, TableInfo.Column> _columnsReservationItems = new HashMap<String, TableInfo.Column>(9);
        _columnsReservationItems.put("id", new TableInfo.Column("id", "INTEGER", true, 1, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("reservationUuid", new TableInfo.Column("reservationUuid", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("productCode", new TableInfo.Column("productCode", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("productName", new TableInfo.Column("productName", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("category", new TableInfo.Column("category", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("qty", new TableInfo.Column("qty", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("price", new TableInfo.Column("price", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("stockSnapshot", new TableInfo.Column("stockSnapshot", "REAL", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsReservationItems.put("esServicio", new TableInfo.Column("esServicio", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        final HashSet<TableInfo.ForeignKey> _foreignKeysReservationItems = new HashSet<TableInfo.ForeignKey>(1);
        _foreignKeysReservationItems.add(new TableInfo.ForeignKey("reservations", "CASCADE", "NO ACTION", Arrays.asList("reservationUuid"), Arrays.asList("localUuid")));
        final HashSet<TableInfo.Index> _indicesReservationItems = new HashSet<TableInfo.Index>(1);
        _indicesReservationItems.add(new TableInfo.Index("index_reservation_items_reservationUuid", false, Arrays.asList("reservationUuid"), Arrays.asList("ASC")));
        final TableInfo _infoReservationItems = new TableInfo("reservation_items", _columnsReservationItems, _foreignKeysReservationItems, _indicesReservationItems);
        final TableInfo _existingReservationItems = TableInfo.read(db, "reservation_items");
        if (!_infoReservationItems.equals(_existingReservationItems)) {
          return new RoomOpenHelper.ValidationResult(false, "reservation_items(com.palweb.reservasoffline.ReservationItemEntity).\n"
                  + " Expected:\n" + _infoReservationItems + "\n"
                  + " Found:\n" + _existingReservationItems);
        }
        final HashMap<String, TableInfo.Column> _columnsSyncQueue = new HashMap<String, TableInfo.Column>(8);
        _columnsSyncQueue.put("id", new TableInfo.Column("id", "INTEGER", true, 1, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncQueue.put("opType", new TableInfo.Column("opType", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncQueue.put("reservationUuid", new TableInfo.Column("reservationUuid", "TEXT", false, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncQueue.put("payloadJson", new TableInfo.Column("payloadJson", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncQueue.put("createdAtEpoch", new TableInfo.Column("createdAtEpoch", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncQueue.put("attempts", new TableInfo.Column("attempts", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncQueue.put("nextAttemptAtEpoch", new TableInfo.Column("nextAttemptAtEpoch", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncQueue.put("lastError", new TableInfo.Column("lastError", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        final HashSet<TableInfo.ForeignKey> _foreignKeysSyncQueue = new HashSet<TableInfo.ForeignKey>(0);
        final HashSet<TableInfo.Index> _indicesSyncQueue = new HashSet<TableInfo.Index>(0);
        final TableInfo _infoSyncQueue = new TableInfo("sync_queue", _columnsSyncQueue, _foreignKeysSyncQueue, _indicesSyncQueue);
        final TableInfo _existingSyncQueue = TableInfo.read(db, "sync_queue");
        if (!_infoSyncQueue.equals(_existingSyncQueue)) {
          return new RoomOpenHelper.ValidationResult(false, "sync_queue(com.palweb.reservasoffline.SyncQueueEntity).\n"
                  + " Expected:\n" + _infoSyncQueue + "\n"
                  + " Found:\n" + _existingSyncQueue);
        }
        final HashMap<String, TableInfo.Column> _columnsSyncHistory = new HashMap<String, TableInfo.Column>(7);
        _columnsSyncHistory.put("id", new TableInfo.Column("id", "INTEGER", true, 1, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncHistory.put("action", new TableInfo.Column("action", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncHistory.put("success", new TableInfo.Column("success", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncHistory.put("detail", new TableInfo.Column("detail", "TEXT", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncHistory.put("itemsTotal", new TableInfo.Column("itemsTotal", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncHistory.put("itemsOk", new TableInfo.Column("itemsOk", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        _columnsSyncHistory.put("createdAtEpoch", new TableInfo.Column("createdAtEpoch", "INTEGER", true, 0, null, TableInfo.CREATED_FROM_ENTITY));
        final HashSet<TableInfo.ForeignKey> _foreignKeysSyncHistory = new HashSet<TableInfo.ForeignKey>(0);
        final HashSet<TableInfo.Index> _indicesSyncHistory = new HashSet<TableInfo.Index>(1);
        _indicesSyncHistory.add(new TableInfo.Index("index_sync_history_createdAtEpoch", false, Arrays.asList("createdAtEpoch"), Arrays.asList("ASC")));
        final TableInfo _infoSyncHistory = new TableInfo("sync_history", _columnsSyncHistory, _foreignKeysSyncHistory, _indicesSyncHistory);
        final TableInfo _existingSyncHistory = TableInfo.read(db, "sync_history");
        if (!_infoSyncHistory.equals(_existingSyncHistory)) {
          return new RoomOpenHelper.ValidationResult(false, "sync_history(com.palweb.reservasoffline.SyncHistoryEntity).\n"
                  + " Expected:\n" + _infoSyncHistory + "\n"
                  + " Found:\n" + _existingSyncHistory);
        }
        return new RoomOpenHelper.ValidationResult(true, null);
      }
    }, "29134e42c567d2ba855e67190df67db1", "1850a1b0f8d62c18bee9c88b7a95f4d6");
    final SupportSQLiteOpenHelper.Configuration _sqliteConfig = SupportSQLiteOpenHelper.Configuration.builder(config.context).name(config.name).callback(_openCallback).build();
    final SupportSQLiteOpenHelper _helper = config.sqliteOpenHelperFactory.create(_sqliteConfig);
    return _helper;
  }

  @Override
  @NonNull
  protected InvalidationTracker createInvalidationTracker() {
    final HashMap<String, String> _shadowTablesMap = new HashMap<String, String>(0);
    final HashMap<String, Set<String>> _viewTables = new HashMap<String, Set<String>>(0);
    return new InvalidationTracker(this, _shadowTablesMap, _viewTables, "products","clients","reservations","reservation_items","sync_queue","sync_history");
  }

  @Override
  public void clearAllTables() {
    super.assertNotMainThread();
    final SupportSQLiteDatabase _db = super.getOpenHelper().getWritableDatabase();
    final boolean _supportsDeferForeignKeys = android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP;
    try {
      if (!_supportsDeferForeignKeys) {
        _db.execSQL("PRAGMA foreign_keys = FALSE");
      }
      super.beginTransaction();
      if (_supportsDeferForeignKeys) {
        _db.execSQL("PRAGMA defer_foreign_keys = TRUE");
      }
      _db.execSQL("DELETE FROM `products`");
      _db.execSQL("DELETE FROM `clients`");
      _db.execSQL("DELETE FROM `reservations`");
      _db.execSQL("DELETE FROM `reservation_items`");
      _db.execSQL("DELETE FROM `sync_queue`");
      _db.execSQL("DELETE FROM `sync_history`");
      super.setTransactionSuccessful();
    } finally {
      super.endTransaction();
      if (!_supportsDeferForeignKeys) {
        _db.execSQL("PRAGMA foreign_keys = TRUE");
      }
      _db.query("PRAGMA wal_checkpoint(FULL)").close();
      if (!_db.inTransaction()) {
        _db.execSQL("VACUUM");
      }
    }
  }

  @Override
  @NonNull
  protected Map<Class<?>, List<Class<?>>> getRequiredTypeConverters() {
    final HashMap<Class<?>, List<Class<?>>> _typeConvertersMap = new HashMap<Class<?>, List<Class<?>>>();
    _typeConvertersMap.put(ProductDao.class, ProductDao_Impl.getRequiredConverters());
    _typeConvertersMap.put(ClientDao.class, ClientDao_Impl.getRequiredConverters());
    _typeConvertersMap.put(ReservationDao.class, ReservationDao_Impl.getRequiredConverters());
    _typeConvertersMap.put(QueueDao.class, QueueDao_Impl.getRequiredConverters());
    _typeConvertersMap.put(SyncHistoryDao.class, SyncHistoryDao_Impl.getRequiredConverters());
    return _typeConvertersMap;
  }

  @Override
  @NonNull
  public Set<Class<? extends AutoMigrationSpec>> getRequiredAutoMigrationSpecs() {
    final HashSet<Class<? extends AutoMigrationSpec>> _autoMigrationSpecsSet = new HashSet<Class<? extends AutoMigrationSpec>>();
    return _autoMigrationSpecsSet;
  }

  @Override
  @NonNull
  public List<Migration> getAutoMigrations(
      @NonNull final Map<Class<? extends AutoMigrationSpec>, AutoMigrationSpec> autoMigrationSpecs) {
    final List<Migration> _autoMigrations = new ArrayList<Migration>();
    return _autoMigrations;
  }

  @Override
  public ProductDao productDao() {
    if (_productDao != null) {
      return _productDao;
    } else {
      synchronized(this) {
        if(_productDao == null) {
          _productDao = new ProductDao_Impl(this);
        }
        return _productDao;
      }
    }
  }

  @Override
  public ClientDao clientDao() {
    if (_clientDao != null) {
      return _clientDao;
    } else {
      synchronized(this) {
        if(_clientDao == null) {
          _clientDao = new ClientDao_Impl(this);
        }
        return _clientDao;
      }
    }
  }

  @Override
  public ReservationDao reservationDao() {
    if (_reservationDao != null) {
      return _reservationDao;
    } else {
      synchronized(this) {
        if(_reservationDao == null) {
          _reservationDao = new ReservationDao_Impl(this);
        }
        return _reservationDao;
      }
    }
  }

  @Override
  public QueueDao queueDao() {
    if (_queueDao != null) {
      return _queueDao;
    } else {
      synchronized(this) {
        if(_queueDao == null) {
          _queueDao = new QueueDao_Impl(this);
        }
        return _queueDao;
      }
    }
  }

  @Override
  public SyncHistoryDao syncHistoryDao() {
    if (_syncHistoryDao != null) {
      return _syncHistoryDao;
    } else {
      synchronized(this) {
        if(_syncHistoryDao == null) {
          _syncHistoryDao = new SyncHistoryDao_Impl(this);
        }
        return _syncHistoryDao;
      }
    }
  }
}
