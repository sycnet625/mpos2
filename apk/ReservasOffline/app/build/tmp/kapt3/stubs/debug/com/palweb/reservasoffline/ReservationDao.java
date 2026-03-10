package com.palweb.reservasoffline;

@kotlin.Metadata(mv = {1, 9, 0}, k = 1, xi = 48, d1 = {"\u0000@\n\u0002\u0018\u0002\n\u0002\u0010\u0000\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0010\u000e\n\u0002\b\u0003\n\u0002\u0010\t\n\u0002\b\u0002\n\u0002\u0010\u0002\n\u0002\b\u0005\n\u0002\u0018\u0002\n\u0002\u0010\b\n\u0002\b\u0004\n\u0002\u0010 \n\u0002\u0018\u0002\n\u0002\b\t\bg\u0018\u00002\u00020\u0001J\u0018\u0010\u0002\u001a\u0004\u0018\u00010\u00032\u0006\u0010\u0004\u001a\u00020\u0005H\u00a7@\u00a2\u0006\u0002\u0010\u0006J\u0018\u0010\u0007\u001a\u0004\u0018\u00010\u00032\u0006\u0010\b\u001a\u00020\tH\u00a7@\u00a2\u0006\u0002\u0010\nJ\u000e\u0010\u000b\u001a\u00020\fH\u00a7@\u00a2\u0006\u0002\u0010\rJ\u000e\u0010\u000e\u001a\u00020\fH\u00a7@\u00a2\u0006\u0002\u0010\rJ\u0016\u0010\u000f\u001a\u00020\f2\u0006\u0010\u0010\u001a\u00020\u0005H\u00a7@\u00a2\u0006\u0002\u0010\u0006J\u000e\u0010\u0011\u001a\b\u0012\u0004\u0012\u00020\u00130\u0012H\'J\u000e\u0010\u0014\u001a\b\u0012\u0004\u0012\u00020\u00130\u0012H\'J\u0018\u0010\u0015\u001a\u0004\u0018\u00010\u00032\u0006\u0010\b\u001a\u00020\tH\u00a7@\u00a2\u0006\u0002\u0010\nJ\u001c\u0010\u0016\u001a\u00020\f2\f\u0010\u0017\u001a\b\u0012\u0004\u0012\u00020\u00190\u0018H\u00a7@\u00a2\u0006\u0002\u0010\u001aJ\u001c\u0010\u001b\u001a\b\u0012\u0004\u0012\u00020\u00190\u00182\u0006\u0010\u0004\u001a\u00020\u0005H\u00a7@\u00a2\u0006\u0002\u0010\u0006J\u0014\u0010\u001c\u001a\u000e\u0012\n\u0012\b\u0012\u0004\u0012\u00020\u00030\u00180\u0012H\'J\u0018\u0010\u001d\u001a\n\u0012\u0006\u0012\u0004\u0018\u00010\u00030\u00122\u0006\u0010\u0004\u001a\u00020\u0005H\'J\u0016\u0010\u001e\u001a\u00020\t2\u0006\u0010\u001f\u001a\u00020\u0003H\u00a7@\u00a2\u0006\u0002\u0010 J\u001c\u0010!\u001a\u00020\f2\f\u0010\u0017\u001a\b\u0012\u0004\u0012\u00020\u00030\u0018H\u00a7@\u00a2\u0006\u0002\u0010\u001a\u00a8\u0006\""}, d2 = {"Lcom/palweb/reservasoffline/ReservationDao;", "", "byLocalUuid", "Lcom/palweb/reservasoffline/ReservationEntity;", "uuid", "", "(Ljava/lang/String;Lkotlin/coroutines/Continuation;)Ljava/lang/Object;", "byRemoteId", "remoteId", "", "(JLkotlin/coroutines/Continuation;)Ljava/lang/Object;", "clear", "", "(Lkotlin/coroutines/Continuation;)Ljava/lang/Object;", "clearAllItems", "clearItems", "reservationUuid", "countNeedsSyncFlow", "Lkotlinx/coroutines/flow/Flow;", "", "countPending", "findByRemote", "insertItems", "items", "", "Lcom/palweb/reservasoffline/ReservationItemEntity;", "(Ljava/util/List;Lkotlin/coroutines/Continuation;)Ljava/lang/Object;", "itemsByUuid", "observeAll", "observeByUuid", "upsert", "item", "(Lcom/palweb/reservasoffline/ReservationEntity;Lkotlin/coroutines/Continuation;)Ljava/lang/Object;", "upsertAll", "app_debug"})
@androidx.room.Dao()
public abstract interface ReservationDao {
    
    @androidx.room.Insert(onConflict = 1)
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object upsert(@org.jetbrains.annotations.NotNull()
    com.palweb.reservasoffline.ReservationEntity item, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super java.lang.Long> $completion);
    
    @androidx.room.Insert(onConflict = 1)
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object upsertAll(@org.jetbrains.annotations.NotNull()
    java.util.List<com.palweb.reservasoffline.ReservationEntity> items, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super kotlin.Unit> $completion);
    
    @androidx.room.Query(value = "DELETE FROM reservation_items WHERE reservationUuid = :reservationUuid")
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object clearItems(@org.jetbrains.annotations.NotNull()
    java.lang.String reservationUuid, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super kotlin.Unit> $completion);
    
    @androidx.room.Insert(onConflict = 1)
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object insertItems(@org.jetbrains.annotations.NotNull()
    java.util.List<com.palweb.reservasoffline.ReservationItemEntity> items, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super kotlin.Unit> $completion);
    
    @androidx.room.Query(value = "SELECT * FROM reservations ORDER BY fechaReservaEpoch ASC")
    @org.jetbrains.annotations.NotNull()
    public abstract kotlinx.coroutines.flow.Flow<java.util.List<com.palweb.reservasoffline.ReservationEntity>> observeAll();
    
    @androidx.room.Query(value = "SELECT * FROM reservations WHERE localUuid = :uuid LIMIT 1")
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object byLocalUuid(@org.jetbrains.annotations.NotNull()
    java.lang.String uuid, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super com.palweb.reservasoffline.ReservationEntity> $completion);
    
    @androidx.room.Query(value = "SELECT * FROM reservations WHERE remoteId = :remoteId LIMIT 1")
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object byRemoteId(long remoteId, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super com.palweb.reservasoffline.ReservationEntity> $completion);
    
    @androidx.room.Query(value = "SELECT * FROM reservation_items WHERE reservationUuid = :uuid")
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object itemsByUuid(@org.jetbrains.annotations.NotNull()
    java.lang.String uuid, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super java.util.List<com.palweb.reservasoffline.ReservationItemEntity>> $completion);
    
    @androidx.room.Query(value = "SELECT * FROM reservations WHERE localUuid = :uuid LIMIT 1")
    @org.jetbrains.annotations.NotNull()
    public abstract kotlinx.coroutines.flow.Flow<com.palweb.reservasoffline.ReservationEntity> observeByUuid(@org.jetbrains.annotations.NotNull()
    java.lang.String uuid);
    
    @androidx.room.Query(value = "SELECT * FROM reservations WHERE remoteId = :remoteId LIMIT 1")
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object findByRemote(long remoteId, @org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super com.palweb.reservasoffline.ReservationEntity> $completion);
    
    @androidx.room.Query(value = "SELECT COUNT(*) FROM reservations WHERE estadoReserva = \'PENDIENTE\'")
    @org.jetbrains.annotations.NotNull()
    public abstract kotlinx.coroutines.flow.Flow<java.lang.Integer> countPending();
    
    @androidx.room.Query(value = "SELECT COUNT(*) FROM reservations WHERE needsSync = 1")
    @org.jetbrains.annotations.NotNull()
    public abstract kotlinx.coroutines.flow.Flow<java.lang.Integer> countNeedsSyncFlow();
    
    @androidx.room.Query(value = "DELETE FROM reservations")
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object clear(@org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super kotlin.Unit> $completion);
    
    @androidx.room.Query(value = "DELETE FROM reservation_items")
    @org.jetbrains.annotations.Nullable()
    public abstract java.lang.Object clearAllItems(@org.jetbrains.annotations.NotNull()
    kotlin.coroutines.Continuation<? super kotlin.Unit> $completion);
}