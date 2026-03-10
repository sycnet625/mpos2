package com.palweb.reservasoffline;

@kotlin.Metadata(mv = {1, 9, 0}, k = 1, xi = 48, d1 = {"\u0000,\n\u0002\u0018\u0002\n\u0002\u0018\u0002\n\u0002\b\u0002\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0002\b\u0002\b\'\u0018\u0000 \r2\u00020\u0001:\u0001\rB\u0005\u00a2\u0006\u0002\u0010\u0002J\b\u0010\u0003\u001a\u00020\u0004H&J\b\u0010\u0005\u001a\u00020\u0006H&J\b\u0010\u0007\u001a\u00020\bH&J\b\u0010\t\u001a\u00020\nH&J\b\u0010\u000b\u001a\u00020\fH&\u00a8\u0006\u000e"}, d2 = {"Lcom/palweb/reservasoffline/AppDatabase;", "Landroidx/room/RoomDatabase;", "()V", "clientDao", "Lcom/palweb/reservasoffline/ClientDao;", "productDao", "Lcom/palweb/reservasoffline/ProductDao;", "queueDao", "Lcom/palweb/reservasoffline/QueueDao;", "reservationDao", "Lcom/palweb/reservasoffline/ReservationDao;", "syncHistoryDao", "Lcom/palweb/reservasoffline/SyncHistoryDao;", "Companion", "app_debug"})
@androidx.room.Database(entities = {com.palweb.reservasoffline.ProductEntity.class, com.palweb.reservasoffline.ClientEntity.class, com.palweb.reservasoffline.ReservationEntity.class, com.palweb.reservasoffline.ReservationItemEntity.class, com.palweb.reservasoffline.SyncQueueEntity.class, com.palweb.reservasoffline.SyncHistoryEntity.class}, version = 2, exportSchema = false)
public abstract class AppDatabase extends androidx.room.RoomDatabase {
    @org.jetbrains.annotations.NotNull()
    private static final java.util.Map<java.lang.String, com.palweb.reservasoffline.AppDatabase> INSTANCES = null;
    @org.jetbrains.annotations.NotNull()
    public static final com.palweb.reservasoffline.AppDatabase.Companion Companion = null;
    
    public AppDatabase() {
        super();
    }
    
    @org.jetbrains.annotations.NotNull()
    public abstract com.palweb.reservasoffline.ProductDao productDao();
    
    @org.jetbrains.annotations.NotNull()
    public abstract com.palweb.reservasoffline.ClientDao clientDao();
    
    @org.jetbrains.annotations.NotNull()
    public abstract com.palweb.reservasoffline.ReservationDao reservationDao();
    
    @org.jetbrains.annotations.NotNull()
    public abstract com.palweb.reservasoffline.QueueDao queueDao();
    
    @org.jetbrains.annotations.NotNull()
    public abstract com.palweb.reservasoffline.SyncHistoryDao syncHistoryDao();
    
    @kotlin.Metadata(mv = {1, 9, 0}, k = 1, xi = 48, d1 = {"\u0000(\n\u0002\u0018\u0002\n\u0002\u0010\u0000\n\u0002\b\u0002\n\u0002\u0010%\n\u0002\u0010\u000e\n\u0002\u0018\u0002\n\u0002\b\u0002\n\u0002\u0018\u0002\n\u0000\n\u0002\u0010\b\n\u0000\b\u0086\u0003\u0018\u00002\u00020\u0001B\u0007\b\u0002\u00a2\u0006\u0002\u0010\u0002J\u0018\u0010\u0007\u001a\u00020\u00062\u0006\u0010\b\u001a\u00020\t2\b\b\u0002\u0010\n\u001a\u00020\u000bR\u001a\u0010\u0003\u001a\u000e\u0012\u0004\u0012\u00020\u0005\u0012\u0004\u0012\u00020\u00060\u0004X\u0082\u0004\u00a2\u0006\u0002\n\u0000\u00a8\u0006\f"}, d2 = {"Lcom/palweb/reservasoffline/AppDatabase$Companion;", "", "()V", "INSTANCES", "", "", "Lcom/palweb/reservasoffline/AppDatabase;", "get", "context", "Landroid/content/Context;", "sucursalId", "", "app_debug"})
    public static final class Companion {
        
        private Companion() {
            super();
        }
        
        @org.jetbrains.annotations.NotNull()
        public final com.palweb.reservasoffline.AppDatabase get(@org.jetbrains.annotations.NotNull()
        android.content.Context context, int sucursalId) {
            return null;
        }
    }
}