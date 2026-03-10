package com.palweb.reservasoffline;

@kotlin.Metadata(mv = {1, 9, 0}, k = 2, xi = 48, d1 = {"\u0000Z\n\u0000\n\u0002\u0010\u0002\n\u0000\n\u0002\u0010\u000b\n\u0000\n\u0002\u0010\u000e\n\u0000\n\u0002\u0018\u0002\n\u0002\b\u0002\n\u0002\u0018\u0002\n\u0002\b\u0002\n\u0002\u0010 \n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0002\b\u000b\n\u0002\u0010\b\n\u0000\n\u0002\u0018\u0002\n\u0000\n\u0002\u0018\u0002\n\u0002\b\u0019\n\u0002\u0018\u0002\n\u0002\b\u0003\n\u0002\u0010\t\n\u0002\b\u0002\u001a&\u0010\u0000\u001a\u00020\u00012\u0006\u0010\u0002\u001a\u00020\u00032\u0006\u0010\u0004\u001a\u00020\u00052\f\u0010\u0006\u001a\b\u0012\u0004\u0012\u00020\u00010\u0007H\u0003\u001a\u0012\u0010\b\u001a\u00020\u00012\b\b\u0002\u0010\t\u001a\u00020\nH\u0003\u001a*\u0010\u000b\u001a\u00020\u00012\f\u0010\f\u001a\b\u0012\u0004\u0012\u00020\u000e0\r2\u0012\u0010\u000f\u001a\u000e\u0012\u0004\u0012\u00020\u0005\u0012\u0004\u0012\u00020\u00010\u0010H\u0003\u001a,\u0010\u0011\u001a\u00020\u00012\u0006\u0010\u0004\u001a\u00020\u00052\u0006\u0010\u0012\u001a\u00020\u00032\u0012\u0010\u0013\u001a\u000e\u0012\u0004\u0012\u00020\u0003\u0012\u0004\u0012\u00020\u00010\u0010H\u0003\u001a&\u0010\u0014\u001a\u00020\u00012\u0006\u0010\t\u001a\u00020\n2\u0006\u0010\u0015\u001a\u00020\u00052\f\u0010\u0016\u001a\b\u0012\u0004\u0012\u00020\u00010\u0007H\u0003\u001a\u001e\u0010\u0017\u001a\u00020\u00012\u0006\u0010\u0018\u001a\u00020\u00052\f\u0010\u0016\u001a\b\u0012\u0004\u0012\u00020\u00010\u0007H\u0003\u001a\u0016\u0010\u0019\u001a\u00020\u00012\f\u0010\u0016\u001a\b\u0012\u0004\u0012\u00020\u00010\u0007H\u0003\u001a4\u0010\u001a\u001a\u00020\u00012\u0006\u0010\u0004\u001a\u00020\u00052\u0006\u0010\u001b\u001a\u00020\u001c2\u0006\u0010\u001d\u001a\u00020\u001e2\b\b\u0002\u0010\u001f\u001a\u00020 H\u0003\u00f8\u0001\u0000\u00a2\u0006\u0004\b!\u0010\"\u001a\u0016\u0010#\u001a\u00020\u00012\f\u0010$\u001a\b\u0012\u0004\u0012\u00020\u000e0\rH\u0003\u001a\u0010\u0010%\u001a\u00020\u00012\u0006\u0010\u0004\u001a\u00020\u0005H\u0003\u001a8\u0010&\u001a\u00020\u00012\u0006\u0010\'\u001a\u00020\u00032\u0006\u0010(\u001a\u00020\u001c2\u0006\u0010)\u001a\u00020\u00052\u0006\u0010*\u001a\u00020\u00032\u0006\u0010+\u001a\u00020\u001c2\u0006\u0010,\u001a\u00020\u001cH\u0003\u001aV\u0010-\u001a\u00020\u00012\u0006\u0010.\u001a\u00020\u000e2\f\u0010/\u001a\b\u0012\u0004\u0012\u00020\u00010\u00072\f\u00100\u001a\b\u0012\u0004\u0012\u00020\u00010\u00072\f\u00101\u001a\b\u0012\u0004\u0012\u00020\u00010\u00072\f\u00102\u001a\b\u0012\u0004\u0012\u00020\u00010\u00072\f\u00103\u001a\b\u0012\u0004\u0012\u00020\u00010\u0007H\u0003\u001a(\u00104\u001a\u00020\u00012\u0006\u0010\t\u001a\u00020\n2\b\u00105\u001a\u0004\u0018\u00010\u00052\f\u0010\u0016\u001a\b\u0012\u0004\u0012\u00020\u00010\u0007H\u0003\u001a\u001e\u00106\u001a\u00020\u00012\u0006\u0010\t\u001a\u00020\n2\f\u0010\u0016\u001a\b\u0012\u0004\u0012\u00020\u00010\u0007H\u0003\u001a8\u00107\u001a\u00020\u00012\u0006\u00108\u001a\u00020\u00052\f\u0010\u0016\u001a\b\u0012\u0004\u0012\u00020\u00010\u00072\u0018\u00109\u001a\u0014\u0012\u0004\u0012\u00020\u0005\u0012\u0004\u0012\u00020\u0005\u0012\u0004\u0012\u00020\u00010:H\u0003\u001a\u0010\u0010;\u001a\u00020\u00012\u0006\u0010\t\u001a\u00020\nH\u0003\u001a\u0018\u0010<\u001a\u00020\u00032\u0006\u0010=\u001a\u00020>2\u0006\u0010?\u001a\u00020>H\u0002\u0082\u0002\u0007\n\u0005\b\u00a1\u001e0\u0001\u00a8\u0006@"}, d2 = {"AppAssistChip", "", "selected", "", "label", "", "onClick", "Lkotlin/Function0;", "AppRoot", "vm", "Lcom/palweb/reservasoffline/MainViewModel;", "CalendarLikeView", "reservations", "", "Lcom/palweb/reservasoffline/ReservationEntity;", "onOpen", "Lkotlin/Function1;", "ConflictFieldToggle", "useServer", "onChange", "ConflictResolverDialog", "localUuid", "onClose", "DiagnosticDialog", "report", "HelpDialog", "KpiCard", "value", "", "color", "Landroidx/compose/ui/graphics/Color;", "modifier", "Landroidx/compose/ui/Modifier;", "KpiCard-9LQNqLg", "(Ljava/lang/String;IJLandroidx/compose/ui/Modifier;)V", "KpiRow", "all", "LoadingOverlay", "NetworkBanner", "online", "queueCount", "status", "syncing", "pendingReservations", "localProducts", "ReservationCard", "reservation", "onEdit", "onComplete", "onCancel", "onResolveConflict", "onStatus", "ReservationFormDialog", "editingUuid", "SettingsDialog", "StatusDialog", "currentNote", "onSave", "Lkotlin/Function2;", "SyncHistoryScreen", "sameDay", "a", "", "b", "app_debug"})
public final class MainActivityKt {
    
    @kotlin.OptIn(markerClass = {androidx.compose.material3.ExperimentalMaterial3Api.class})
    @androidx.compose.runtime.Composable()
    private static final void AppRoot(com.palweb.reservasoffline.MainViewModel vm) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void SyncHistoryScreen(com.palweb.reservasoffline.MainViewModel vm) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void AppAssistChip(boolean selected, java.lang.String label, kotlin.jvm.functions.Function0<kotlin.Unit> onClick) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void LoadingOverlay(java.lang.String label) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void NetworkBanner(boolean online, int queueCount, java.lang.String status, boolean syncing, int pendingReservations, int localProducts) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void KpiRow(java.util.List<com.palweb.reservasoffline.ReservationEntity> all) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void ReservationCard(com.palweb.reservasoffline.ReservationEntity reservation, kotlin.jvm.functions.Function0<kotlin.Unit> onEdit, kotlin.jvm.functions.Function0<kotlin.Unit> onComplete, kotlin.jvm.functions.Function0<kotlin.Unit> onCancel, kotlin.jvm.functions.Function0<kotlin.Unit> onResolveConflict, kotlin.jvm.functions.Function0<kotlin.Unit> onStatus) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void ConflictResolverDialog(com.palweb.reservasoffline.MainViewModel vm, java.lang.String localUuid, kotlin.jvm.functions.Function0<kotlin.Unit> onClose) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void ConflictFieldToggle(java.lang.String label, boolean useServer, kotlin.jvm.functions.Function1<? super java.lang.Boolean, kotlin.Unit> onChange) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void CalendarLikeView(java.util.List<com.palweb.reservasoffline.ReservationEntity> reservations, kotlin.jvm.functions.Function1<? super java.lang.String, kotlin.Unit> onOpen) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void SettingsDialog(com.palweb.reservasoffline.MainViewModel vm, kotlin.jvm.functions.Function0<kotlin.Unit> onClose) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void HelpDialog(kotlin.jvm.functions.Function0<kotlin.Unit> onClose) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void DiagnosticDialog(java.lang.String report, kotlin.jvm.functions.Function0<kotlin.Unit> onClose) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void StatusDialog(java.lang.String currentNote, kotlin.jvm.functions.Function0<kotlin.Unit> onClose, kotlin.jvm.functions.Function2<? super java.lang.String, ? super java.lang.String, kotlin.Unit> onSave) {
    }
    
    @androidx.compose.runtime.Composable()
    private static final void ReservationFormDialog(com.palweb.reservasoffline.MainViewModel vm, java.lang.String editingUuid, kotlin.jvm.functions.Function0<kotlin.Unit> onClose) {
    }
    
    private static final boolean sameDay(long a, long b) {
        return false;
    }
}