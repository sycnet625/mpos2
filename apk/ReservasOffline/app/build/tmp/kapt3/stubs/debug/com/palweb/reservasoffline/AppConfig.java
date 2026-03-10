package com.palweb.reservasoffline;

@kotlin.Metadata(mv = {1, 9, 0}, k = 1, xi = 48, d1 = {"\u00000\n\u0002\u0018\u0002\n\u0002\u0010\u0000\n\u0000\n\u0002\u0018\u0002\n\u0002\b\u0002\n\u0002\u0010\u000e\n\u0002\b\b\n\u0002\u0010\t\n\u0002\b\u0012\n\u0002\u0018\u0002\n\u0000\n\u0002\u0010\b\n\u0002\b\b\u0018\u00002\u00020\u0001B\r\u0012\u0006\u0010\u0002\u001a\u00020\u0003\u00a2\u0006\u0002\u0010\u0004J\u000e\u0010*\u001a\u00020\u00062\u0006\u0010+\u001a\u00020\u0006R$\u0010\u0007\u001a\u00020\u00062\u0006\u0010\u0005\u001a\u00020\u00068F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b\b\u0010\t\"\u0004\b\n\u0010\u000bR$\u0010\f\u001a\u00020\u00062\u0006\u0010\u0005\u001a\u00020\u00068F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b\r\u0010\t\"\u0004\b\u000e\u0010\u000bR$\u0010\u0010\u001a\u00020\u000f2\u0006\u0010\u0005\u001a\u00020\u000f8F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b\u0011\u0010\u0012\"\u0004\b\u0013\u0010\u0014R$\u0010\u0015\u001a\u00020\u000f2\u0006\u0010\u0005\u001a\u00020\u000f8F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b\u0016\u0010\u0012\"\u0004\b\u0017\u0010\u0014R$\u0010\u0018\u001a\u00020\u000f2\u0006\u0010\u0005\u001a\u00020\u000f8F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b\u0019\u0010\u0012\"\u0004\b\u001a\u0010\u0014R$\u0010\u001b\u001a\u00020\u000f2\u0006\u0010\u0005\u001a\u00020\u000f8F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b\u001c\u0010\u0012\"\u0004\b\u001d\u0010\u0014R$\u0010\u001e\u001a\u00020\u00062\u0006\u0010\u0005\u001a\u00020\u00068F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b\u001f\u0010\t\"\u0004\b \u0010\u000bR\u0016\u0010!\u001a\n #*\u0004\u0018\u00010\"0\"X\u0082\u0004\u00a2\u0006\u0002\n\u0000R$\u0010%\u001a\u00020$2\u0006\u0010\u0005\u001a\u00020$8F@FX\u0086\u000e\u00a2\u0006\f\u001a\u0004\b&\u0010\'\"\u0004\b(\u0010)\u00a8\u0006,"}, d2 = {"Lcom/palweb/reservasoffline/AppConfig;", "", "context", "Landroid/content/Context;", "(Landroid/content/Context;)V", "value", "", "apiKey", "getApiKey", "()Ljava/lang/String;", "setApiKey", "(Ljava/lang/String;)V", "baseUrl", "getBaseUrl", "setBaseUrl", "", "lastBootstrapEpoch", "getLastBootstrapEpoch", "()J", "setLastBootstrapEpoch", "(J)V", "lastClientsSyncEpoch", "getLastClientsSyncEpoch", "setLastClientsSyncEpoch", "lastProductsSyncEpoch", "getLastProductsSyncEpoch", "setLastProductsSyncEpoch", "lastReservationsSyncEpoch", "getLastReservationsSyncEpoch", "setLastReservationsSyncEpoch", "otaJsonUrl", "getOtaJsonUrl", "setOtaJsonUrl", "prefs", "Landroid/content/SharedPreferences;", "kotlin.jvm.PlatformType", "", "sucursalId", "getSucursalId", "()I", "setSucursalId", "(I)V", "endpoint", "path", "app_debug"})
public final class AppConfig {
    private final android.content.SharedPreferences prefs = null;
    
    public AppConfig(@org.jetbrains.annotations.NotNull()
    android.content.Context context) {
        super();
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.lang.String getBaseUrl() {
        return null;
    }
    
    public final void setBaseUrl(@org.jetbrains.annotations.NotNull()
    java.lang.String value) {
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.lang.String getApiKey() {
        return null;
    }
    
    public final void setApiKey(@org.jetbrains.annotations.NotNull()
    java.lang.String value) {
    }
    
    public final long getLastBootstrapEpoch() {
        return 0L;
    }
    
    public final void setLastBootstrapEpoch(long value) {
    }
    
    public final long getLastProductsSyncEpoch() {
        return 0L;
    }
    
    public final void setLastProductsSyncEpoch(long value) {
    }
    
    public final long getLastClientsSyncEpoch() {
        return 0L;
    }
    
    public final void setLastClientsSyncEpoch(long value) {
    }
    
    public final long getLastReservationsSyncEpoch() {
        return 0L;
    }
    
    public final void setLastReservationsSyncEpoch(long value) {
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.lang.String getOtaJsonUrl() {
        return null;
    }
    
    public final void setOtaJsonUrl(@org.jetbrains.annotations.NotNull()
    java.lang.String value) {
    }
    
    public final int getSucursalId() {
        return 0;
    }
    
    public final void setSucursalId(int value) {
    }
    
    @org.jetbrains.annotations.NotNull()
    public final java.lang.String endpoint(@org.jetbrains.annotations.NotNull()
    java.lang.String path) {
        return null;
    }
}