package com.palweb.reservasoffline;

@kotlin.Metadata(mv = {1, 9, 0}, k = 1, xi = 48, d1 = {"\u0000d\n\u0002\u0018\u0002\n\u0002\u0010\u0000\n\u0000\n\u0002\u0018\u0002\n\u0002\b\u0002\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0010\t\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0010\u000e\n\u0000\n\u0002\u0010 \n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0002\b\u0002\n\u0002\u0018\u0002\n\u0002\b\u0005\n\u0002\u0010\b\n\u0002\b\n\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0000\u0018\u00002\u00020\u0001B\r\u0012\u0006\u0010\u0002\u001a\u00020\u0003\u00a2\u0006\u0002\u0010\u0004J\u0006\u0010\u0005\u001a\u00020\u0006J\u000e\u0010\u0007\u001a\u00020\b2\u0006\u0010\t\u001a\u00020\nJ\u000e\u0010\u000b\u001a\u00020\f2\u0006\u0010\r\u001a\u00020\u000eJ\f\u0010\u000f\u001a\b\u0012\u0004\u0012\u00020\u00110\u0010J\f\u0010\u0012\u001a\b\u0012\u0004\u0012\u00020\u00130\u0010J\f\u0010\u0014\u001a\b\u0012\u0004\u0012\u00020\u00150\u0010J\u0016\u0010\u0016\u001a\b\u0012\u0004\u0012\u00020\u00110\u00102\u0006\u0010\u0017\u001a\u00020\u0018H\u0002J\u0016\u0010\u0019\u001a\b\u0012\u0004\u0012\u00020\u00130\u00102\u0006\u0010\u001a\u001a\u00020\u0018H\u0002J\u001a\u0010\u001b\u001a\u0004\u0018\u00010\u00152\u0006\u0010\u001c\u001a\u00020\b2\u0006\u0010\u001d\u001a\u00020\u001eH\u0002J\u0016\u0010\u001f\u001a\b\u0012\u0004\u0012\u00020\u00150\u00102\u0006\u0010 \u001a\u00020\u0018H\u0002J\"\u0010!\u001a\u00020\b2\u0006\u0010\"\u001a\u00020\u000e2\u0006\u0010#\u001a\u00020\u000e2\b\u0010$\u001a\u0004\u0018\u00010\bH\u0002J\u000e\u0010%\u001a\u00020\b2\u0006\u0010&\u001a\u00020\nJ\u0010\u0010\'\u001a\u0004\u0018\u00010\u00152\u0006\u0010&\u001a\u00020\nJ\u001a\u0010(\u001a\b\u0012\u0004\u0012\u00020)0\u00102\f\u0010*\u001a\b\u0012\u0004\u0012\u00020+0\u0010R\u000e\u0010\u0002\u001a\u00020\u0003X\u0082\u0004\u00a2\u0006\u0002\n\u0000\u00a8\u0006,"}, d2 = {"Lcom/palweb/reservasoffline/OfflineApi;", "", "cfg", "Lcom/palweb/reservasoffline/AppConfig;", "(Lcom/palweb/reservasoffline/AppConfig;)V", "bootstrap", "Lcom/palweb/reservasoffline/BootstrapData;", "changesSince", "Lorg/json/JSONObject;", "epochSeconds", "", "checkOtaUpdate", "Lcom/palweb/reservasoffline/OtaInfo;", "otaJsonUrl", "", "downloadClientsOnly", "", "Lcom/palweb/reservasoffline/ClientEntity;", "downloadProductsOnly", "Lcom/palweb/reservasoffline/ProductEntity;", "downloadReservationsOnly", "Lcom/palweb/reservasoffline/ReservationWithItems;", "parseClients", "clientArr", "Lorg/json/JSONArray;", "parseProducts", "productArr", "parseReservationObject", "r", "index", "", "parseReservations", "reservationArr", "request", "url", "method", "body", "reservationDetail", "remoteId", "reservationDetailParsed", "syncOperations", "Lcom/palweb/reservasoffline/SyncOperationResult;", "ops", "Lcom/palweb/reservasoffline/SyncQueueEntity;", "app_release"})
public final class OfflineApi {
    @org.jetbrains.annotations.NotNull()
    private final com.palweb.reservasoffline.AppConfig cfg = null;
    
    public OfflineApi(@org.jetbrains.annotations.NotNull()
    com.palweb.reservasoffline.AppConfig cfg) {
        super();
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.util.List<com.palweb.reservasoffline.ProductEntity> downloadProductsOnly() {
        return null;
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.util.List<com.palweb.reservasoffline.ClientEntity> downloadClientsOnly() {
        return null;
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.util.List<com.palweb.reservasoffline.ReservationWithItems> downloadReservationsOnly() {
        return null;
    }
    
    @org.jetbrains.annotations.NotNull()
    public final com.palweb.reservasoffline.OtaInfo checkOtaUpdate(@org.jetbrains.annotations.NotNull()
    java.lang.String otaJsonUrl) {
        return null;
    }
    
    @org.jetbrains.annotations.NotNull()
    public final com.palweb.reservasoffline.BootstrapData bootstrap() {
        return null;
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.util.List<com.palweb.reservasoffline.SyncOperationResult> syncOperations(@org.jetbrains.annotations.NotNull()
    java.util.List<com.palweb.reservasoffline.SyncQueueEntity> ops) {
        return null;
    }
    
    @org.jetbrains.annotations.NotNull()
    public final org.json.JSONObject changesSince(long epochSeconds) {
        return null;
    }
    
    @org.jetbrains.annotations.NotNull()
    public final org.json.JSONObject reservationDetail(long remoteId) {
        return null;
    }
    
    @org.jetbrains.annotations.Nullable()
    public final com.palweb.reservasoffline.ReservationWithItems reservationDetailParsed(long remoteId) {
        return null;
    }
    
    private final org.json.JSONObject request(java.lang.String url, java.lang.String method, org.json.JSONObject body) {
        return null;
    }
    
    private final java.util.List<com.palweb.reservasoffline.ProductEntity> parseProducts(org.json.JSONArray productArr) {
        return null;
    }
    
    private final java.util.List<com.palweb.reservasoffline.ClientEntity> parseClients(org.json.JSONArray clientArr) {
        return null;
    }
    
    private final java.util.List<com.palweb.reservasoffline.ReservationWithItems> parseReservations(org.json.JSONArray reservationArr) {
        return null;
    }
    
    private final com.palweb.reservasoffline.ReservationWithItems parseReservationObject(org.json.JSONObject r, int index) {
        return null;
    }
}