package com.palweb.reservasoffline

import android.os.Bundle
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CloudDownload
import androidx.compose.material.icons.filled.CloudUpload
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.HelpOutline
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.AssistChipDefaults
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.FloatingActionButtonDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            ReservasOfflineTheme {
                AppRoot()
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AppRoot(vm: MainViewModel = viewModel()) {
    val reservations by vm.filteredReservations.collectAsStateWithLifecycle()
    val allReservations by vm.reservations.collectAsStateWithLifecycle()
    val queueCount by vm.queueCount.collectAsStateWithLifecycle()
    val pendingReservationsToUpload by vm.pendingReservationsToUpload.collectAsStateWithLifecycle()
    val localProductsCount by vm.localProductsCount.collectAsStateWithLifecycle()
    val syncHistory by vm.syncHistory.collectAsStateWithLifecycle()
    val online by vm.online.collectAsStateWithLifecycle()
    val status by vm.statusMsg.collectAsStateWithLifecycle()
    val toastMessage by vm.toastMessage.collectAsStateWithLifecycle()
    val otaEvent by vm.otaEvent.collectAsStateWithLifecycle()
    val diagnosticReport by vm.diagnosticReport.collectAsStateWithLifecycle()
    val tab by vm.tab.collectAsStateWithLifecycle()
    val estado by vm.estadoFilter.collectAsStateWithLifecycle()
    val fecha by vm.fechaFilter.collectAsStateWithLifecycle()
    val search by vm.searchText.collectAsStateWithLifecycle()
    val bootstrapping by vm.isBootstrapping.collectAsStateWithLifecycle()
    val syncing by vm.isSyncingQueue.collectAsStateWithLifecycle()
    val busy = bootstrapping || syncing

    val haptic = LocalHapticFeedback.current
    val context = LocalContext.current

    LaunchedEffect(toastMessage) {
        toastMessage?.let {
            Toast.makeText(context, it, Toast.LENGTH_LONG).show()
            vm.consumeToast()
        }
    }

    var showForm by remember { mutableStateOf(false) }
    var editingUuid by remember { mutableStateOf<String?>(null) }
    var showSettings by remember { mutableStateOf(false) }
    var showHelp by remember { mutableStateOf(false) }
    var resolveConflictUuid by remember { mutableStateOf<String?>(null) }
    var showStatusDialog by remember { mutableStateOf<Pair<String, String>?>(null) }
    val mainScroll = rememberScrollState()

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Color(0xFF0F172A),
                    titleContentColor = Color.White,
                    actionIconContentColor = Color.White,
                ),
                title = {
                    Column {
                        Text("Gestion de Reservas", fontWeight = FontWeight.ExtraBold)
                        Text("Modo offline primero", style = MaterialTheme.typography.labelSmall, color = Color(0xFFBFDBFE))
                        Text(vm.appVersionLabel, style = MaterialTheme.typography.labelSmall, color = Color(0xFF93C5FD))
                    }
                },
                actions = {
                    IconButton(onClick = {
                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                        showSettings = true
                    }) { Icon(Icons.Default.Settings, null) }
                    IconButton(onClick = {
                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                        showHelp = true
                    }) { Icon(Icons.Default.HelpOutline, "Ayuda") }
                    IconButton(onClick = {
                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                        vm.checkOtaUpdate()
                    }) { Icon(Icons.Default.CloudDownload, "OTA") }
                }
            )
        },
        floatingActionButton = {
            FloatingActionButton(
                containerColor = Color(0xFF4F46E5),
                elevation = FloatingActionButtonDefaults.elevation(defaultElevation = 8.dp),
                onClick = {
                    haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                    editingUuid = null
                    showForm = true
                }
            ) {
                Icon(Icons.Default.Add, null, tint = Color.White)
            }
        }
    ) { pad ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(pad)
                .background(MaterialTheme.colorScheme.background)
                .padding(12.dp)
        ) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .verticalScroll(mainScroll)
            ) {
                AnimatedVisibility(
                    visible = true,
                    enter = slideInVertically(initialOffsetY = { -it }) + fadeIn(),
                    exit = slideOutVertically(targetOffsetY = { -it }) + fadeOut(),
                ) {
                    NetworkBanner(
                        online = online,
                        queueCount = queueCount,
                        status = status,
                        syncing = syncing,
                        pendingReservations = pendingReservationsToUpload,
                        localProducts = localProductsCount,
                    )
                }
                Spacer(Modifier.height(8.dp))
                KpiRow(allReservations)
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                    Button(
                        onClick = {
                            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                            vm.runDownloadProducts()
                        },
                        modifier = Modifier.weight(1f),
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF2563EB)),
                        shape = RoundedCornerShape(12.dp),
                    ) {
                        Icon(Icons.Default.CloudDownload, null)
                        Spacer(Modifier.width(6.dp))
                        Text("Descargar productos")
                    }
                    Button(
                        onClick = {
                            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                            vm.runDownloadReservations()
                        },
                        modifier = Modifier.weight(1f),
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF0EA5E9)),
                        shape = RoundedCornerShape(12.dp),
                    ) {
                        Icon(Icons.Default.CloudDownload, null)
                        Spacer(Modifier.width(6.dp))
                        Text("Descargar reservaciones")
                    }
                }
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                    Button(
                        onClick = {
                            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                            vm.runDownloadClients()
                        },
                        modifier = Modifier.weight(1f),
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF7C3AED)),
                        shape = RoundedCornerShape(12.dp),
                    ) {
                        Icon(Icons.Default.CloudDownload, null)
                        Spacer(Modifier.width(6.dp))
                        Text("Descargar clientes")
                    }
                    Button(
                        onClick = {
                            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                            vm.checkOtaUpdate()
                        },
                        modifier = Modifier.weight(1f),
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF0F172A)),
                        shape = RoundedCornerShape(12.dp),
                    ) {
                        Text("OTA actualizar online")
                    }
                }
                Spacer(Modifier.height(8.dp))
                Button(
                    onClick = {
                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                        vm.runQueueSync()
                    },
                    modifier = Modifier.fillMaxWidth(),
                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A)),
                    shape = RoundedCornerShape(12.dp),
                ) {
                    Icon(Icons.Default.CloudUpload, null)
                    Spacer(Modifier.width(6.dp))
                    Text("Sincronizar y subir reservas locales")
                }
                Spacer(Modifier.height(8.dp))
                OutlinedButton(
                    onClick = {
                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                        vm.runDiagnostics()
                    },
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(12.dp),
                ) {
                    Text("Ejecutar diagnostico")
                }
                Spacer(Modifier.height(8.dp))
                Card(shape = RoundedCornerShape(12.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                    Column(Modifier.fillMaxWidth().padding(10.dp)) {
                        Text("Bitacora de sincronizacion", fontWeight = FontWeight.Bold, color = Color(0xFF1E293B))
                        syncHistory.take(3).forEach { h ->
                            Text(
                                "${epochToText(h.createdAtEpoch)} · ${h.action} · ${if (h.success == 1) "OK" else "ERROR"}",
                                style = MaterialTheme.typography.bodySmall,
                                color = if (h.success == 1) Color(0xFF166534) else Color(0xFF991B1B)
                            )
                        }
                    }
                }
                Spacer(Modifier.height(8.dp))

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    AppAssistChip(selected = tab == "LISTA", label = "Lista") {
                        vm.tab.value = "LISTA"
                    }
                    AppAssistChip(selected = tab == "CAL", label = "Almanaque") {
                        vm.tab.value = "CAL"
                    }
                    AppAssistChip(selected = tab == "SYNC", label = "Historial Sync") {
                        vm.tab.value = "SYNC"
                    }
                    AppAssistChip(selected = estado == "PENDIENTE", label = if (estado == "PENDIENTE") "Pendientes" else "Todos") {
                        vm.estadoFilter.value = if (estado == "PENDIENTE") "TODOS" else "PENDIENTE"
                    }
                    AppAssistChip(selected = fecha != "TODAS", label = "Fecha: $fecha") {
                        vm.fechaFilter.value = when (fecha) {
                            "TODAS" -> "HOY"
                            "HOY" -> "SEMANA"
                            "SEMANA" -> "VENCIDAS"
                            else -> "TODAS"
                        }
                    }
                }
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    modifier = Modifier.fillMaxWidth(),
                    value = search,
                    onValueChange = { vm.searchText.value = it },
                    label = { Text("Buscar cliente") },
                    shape = RoundedCornerShape(14.dp)
                )
                Spacer(Modifier.height(8.dp))

                AnimatedContent(targetState = tab, label = "tab-switch") { currentTab ->
                    if (currentTab == "CAL") {
                        CalendarLikeView(reservations = reservations, onOpen = {
                            editingUuid = it
                            showForm = true
                        })
                    } else if (currentTab == "SYNC") {
                        SyncHistoryScreen(vm = vm)
                    } else {
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            reservations.forEach { r ->
                                ReservationCard(
                                    reservation = r,
                                    onEdit = {
                                        editingUuid = r.localUuid
                                        showForm = true
                                    },
                                    onComplete = { vm.markComplete(r.localUuid) },
                                    onCancel = { vm.markCancel(r.localUuid) },
                                    onResolveConflict = { resolveConflictUuid = r.localUuid },
                                    onStatus = { showStatusDialog = r.localUuid to r.notes },
                                )
                            }
                        }
                    }
                }
                Spacer(Modifier.height(92.dp))
            }

            AnimatedVisibility(
                modifier = Modifier
                    .align(Alignment.TopCenter)
                    .padding(top = 4.dp),
                visible = mainScroll.value > 0,
                enter = fadeIn(),
                exit = fadeOut(),
            ) {
                Card(
                    shape = RoundedCornerShape(12.dp),
                    colors = CardDefaults.cardColors(containerColor = Color(0xCC0F172A))
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Icon(Icons.Default.KeyboardArrowUp, contentDescription = null, tint = Color.White)
                        Text("Mas arriba", color = Color.White, style = MaterialTheme.typography.labelSmall)
                    }
                }
            }

            AnimatedVisibility(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .padding(bottom = 80.dp),
                visible = mainScroll.maxValue > mainScroll.value,
                enter = fadeIn(),
                exit = fadeOut(),
            ) {
                Card(
                    shape = RoundedCornerShape(12.dp),
                    colors = CardDefaults.cardColors(containerColor = Color(0xCC0F172A))
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text("Mas abajo", color = Color.White, style = MaterialTheme.typography.labelSmall)
                        Icon(Icons.Default.KeyboardArrowDown, contentDescription = null, tint = Color.White)
                    }
                }
            }

            AnimatedVisibility(
                modifier = Modifier.align(Alignment.BottomCenter),
                visible = mainScroll.maxValue > mainScroll.value,
                enter = fadeIn(),
                exit = fadeOut(),
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(36.dp)
                        .background(
                            Brush.verticalGradient(
                                colors = listOf(Color.Transparent, Color(0x220F172A))
                            )
                        )
                )
            }

            AnimatedVisibility(
                modifier = Modifier.align(Alignment.Center),
                visible = busy,
                enter = fadeIn(),
                exit = fadeOut(),
            ) {
                LoadingOverlay(label = if (bootstrapping) "Descargando datos..." else "Sincronizando cola...")
            }
        }
    }

    if (showForm) {
        ReservationFormDialog(vm = vm, editingUuid = editingUuid, onClose = { showForm = false })
    }
    if (showSettings) {
        SettingsDialog(vm = vm, onClose = { showSettings = false })
    }
    if (showHelp) {
        HelpDialog(onClose = { showHelp = false })
    }
    resolveConflictUuid?.let { uuid ->
        ConflictResolverDialog(
            vm = vm,
            localUuid = uuid,
            onClose = { resolveConflictUuid = null }
        )
    }
    otaEvent?.let { info ->
        AlertDialog(
            onDismissRequest = { vm.consumeOtaEvent() },
            confirmButton = {
                Button(onClick = { vm.installOtaUpdate(info) }) { Text("Descargar e instalar") }
            },
            dismissButton = { TextButton(onClick = { vm.consumeOtaEvent() }) { Text("Cancelar") } },
            title = { Text("Actualizacion disponible ${info.versionName}") },
            text = {
                Text("Se descargara la APK y se verificara su hash antes de instalar.\n\n${info.notes}")
            }
        )
    }
    diagnosticReport?.let { report ->
        DiagnosticDialog(
            report = report,
            onClose = { vm.consumeDiagnosticReport() }
        )
    }
    showStatusDialog?.let { pair ->
        StatusDialog(
            currentNote = pair.second,
            onClose = { showStatusDialog = null },
            onSave = { estadoNuevo, nota ->
                vm.updateStatus(pair.first, estadoNuevo, nota)
                showStatusDialog = null
            }
        )
    }
}

@Composable
private fun SyncHistoryScreen(vm: MainViewModel) {
    val history by vm.filteredSyncHistory.collectAsStateWithLifecycle()
    val filter by vm.syncHistoryFilter.collectAsStateWithLifecycle()

    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            AppAssistChip(selected = filter == "TODOS", label = "Todos") { vm.syncHistoryFilter.value = "TODOS" }
            AppAssistChip(selected = filter == "OK", label = "OK") { vm.syncHistoryFilter.value = "OK" }
            AppAssistChip(selected = filter == "ERROR", label = "Error") { vm.syncHistoryFilter.value = "ERROR" }
            Button(
                onClick = { vm.exportSyncHistoryCsv() },
                shape = RoundedCornerShape(12.dp),
                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF0F172A))
            ) {
                Text("Exportar CSV")
            }
        }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            history.forEach { h ->
                Card(shape = RoundedCornerShape(12.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                    Column(Modifier.fillMaxWidth().padding(10.dp)) {
                        Text(
                            "${epochToText(h.createdAtEpoch)} · ${h.action}",
                            fontWeight = FontWeight.Bold,
                            color = Color(0xFF1E293B)
                        )
                        Text(
                            "${if (h.success == 1) "OK" else "ERROR"} · ${h.itemsOk}/${h.itemsTotal}",
                            color = if (h.success == 1) Color(0xFF166534) else Color(0xFF991B1B),
                            style = MaterialTheme.typography.bodySmall
                        )
                        if (h.detail.isNotBlank()) {
                            Text(h.detail, style = MaterialTheme.typography.bodySmall, color = Color(0xFF475569))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun AppAssistChip(selected: Boolean, label: String, onClick: () -> Unit) {
    AssistChip(
        onClick = onClick,
        label = { Text(label, fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium) },
        shape = RoundedCornerShape(12.dp),
        colors = AssistChipDefaults.assistChipColors(
            containerColor = if (selected) Color(0xFF0F172A) else Color.White,
            labelColor = if (selected) Color.White else Color(0xFF334155),
        )
    )
}

@Composable
private fun LoadingOverlay(label: String) {
    val transition = rememberInfiniteTransition(label = "loading-pulse")
    val alpha by transition.animateFloat(
        initialValue = 0.65f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(animation = tween(850), repeatMode = RepeatMode.Reverse),
        label = "alpha"
    )

    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White.copy(alpha = alpha)),
        elevation = CardDefaults.cardElevation(defaultElevation = 14.dp),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 18.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.5.dp)
            Text(label, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun NetworkBanner(
    online: Boolean,
    queueCount: Int,
    status: String,
    syncing: Boolean,
    pendingReservations: Int,
    localProducts: Int,
) {
    val bg = if (online) Color(0xFFDCFCE7) else Color(0xFFFEE2E2)
    val fg = if (online) Color(0xFF166534) else Color(0xFF991B1B)
    Card(
        colors = CardDefaults.cardColors(containerColor = bg),
        shape = RoundedCornerShape(12.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                if (online) "Internet disponible" else "Sin internet",
                color = fg,
                fontWeight = FontWeight.SemiBold,
                style = MaterialTheme.typography.labelMedium
            )
            Spacer(Modifier.width(8.dp))
            Text("Cola: $queueCount", color = fg, style = MaterialTheme.typography.labelSmall)
            Spacer(Modifier.width(8.dp))
            Text("Pend: $pendingReservations", color = fg, style = MaterialTheme.typography.labelSmall)
            Spacer(Modifier.width(8.dp))
            Text("Prod: $localProducts", color = fg, style = MaterialTheme.typography.labelSmall)
            Spacer(Modifier.weight(1f))
            if (syncing) {
                CircularProgressIndicator(modifier = Modifier.size(12.dp), strokeWidth = 1.8.dp, color = fg)
                Spacer(Modifier.width(4.dp))
            }
            Text(
                status,
                color = fg,
                style = MaterialTheme.typography.labelSmall,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis
            )
        }
    }
}

@Composable
private fun KpiRow(all: List<ReservationEntity>) {
    val now = System.currentTimeMillis()
    val pending = all.count { it.estadoReserva == "PENDIENTE" }
    val today = all.count { it.estadoReserva == "PENDIENTE" && sameDay(now, it.fechaReservaEpoch) }
    val late = all.count { it.estadoReserva == "PENDIENTE" && it.fechaReservaEpoch < now }
    val stock = all.count { it.estadoReserva == "PENDIENTE" && it.sinExistencia == 1 }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
        KpiCard("Pendientes", pending, Color(0xFF6366F1), Modifier.weight(1f))
        KpiCard("Hoy", today, Color(0xFFF59E0B), Modifier.weight(1f))
        KpiCard("Vencidas", late, Color(0xFFEF4444), Modifier.weight(1f))
        KpiCard("Sin stock", stock, Color(0xFFDC2626), Modifier.weight(1f))
    }
}

@Composable
private fun KpiCard(label: String, value: Int, color: Color, modifier: Modifier = Modifier) {
    Card(modifier = modifier, shape = RoundedCornerShape(14.dp), elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)) {
        Column(Modifier.padding(10.dp)) {
            Text(value.toString(), color = color, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.ExtraBold)
            Text(label, color = Color(0xFF475569), style = MaterialTheme.typography.labelMedium)
        }
    }
}

@Composable
private fun ReservationCard(
    reservation: ReservationEntity,
    onEdit: () -> Unit,
    onComplete: () -> Unit,
    onCancel: () -> Unit,
    onResolveConflict: () -> Unit,
    onStatus: () -> Unit,
) {
    val now = System.currentTimeMillis()
    val late = reservation.estadoReserva == "PENDIENTE" && reservation.fechaReservaEpoch < now
    val textColor = if (late) Color.Black else Color(0xFF0F172A)
    val mutedTextColor = if (late) Color.Black.copy(alpha = 0.82f) else Color(0xFF334155)
    val cardColor by animateColorAsState(
        targetValue = when {
            reservation.estadoReserva == "ENTREGADO" -> Color(0xFFECFDF5)
            reservation.estadoReserva == "CANCELADO" -> Color(0xFFF8FAFC)
            late -> Color(0xFFFFE4E6)
            else -> Color.White
        },
        animationSpec = tween(250),
        label = "res-card"
    )

    Card(colors = CardDefaults.cardColors(containerColor = cardColor), shape = RoundedCornerShape(16.dp)) {
        Column(Modifier.fillMaxWidth().padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("#${reservation.remoteId ?: "LOCAL"}", fontWeight = FontWeight.Bold, color = textColor)
                Spacer(Modifier.width(8.dp))
                Text(reservation.clientName, fontWeight = FontWeight.Bold, color = textColor)
                Spacer(Modifier.weight(1f))
                if (reservation.needsSync == 1) {
                    Text("Pendiente", color = Color(0xFFB45309), fontWeight = FontWeight.SemiBold)
                }
            }
            Text(epochToText(reservation.fechaReservaEpoch), color = mutedTextColor)
            Text("Total: $${"%.2f".format(reservation.total)}  Abono: $${"%.2f".format(reservation.abono)}", color = textColor)
            Text("Estado: ${reservation.estadoReserva}  Pago: ${reservation.estadoPago}  Origen: ${reservation.canalOrigen}", color = mutedTextColor)
            if (reservation.sinExistencia == 1) Text("Sin existencia", color = Color(0xFFB91C1C), fontWeight = FontWeight.Bold)
            if (reservation.syncAttempts > 0 || reservation.syncError.isNotBlank()) {
                Text(
                    "Sync: intentos=${reservation.syncAttempts} ${reservation.syncError}",
                    color = Color(0xFFB45309),
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                OutlinedButton(onClick = onStatus, shape = RoundedCornerShape(12.dp)) { Text("Estado") }
                OutlinedButton(onClick = onEdit, shape = RoundedCornerShape(12.dp)) {
                    Icon(Icons.Default.Edit, null)
                    Spacer(Modifier.width(4.dp))
                    Text("Editar")
                }
                Button(
                    onClick = onComplete,
                    shape = RoundedCornerShape(12.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A))
                ) { Text("Entregar") }
                OutlinedButton(onClick = onCancel, shape = RoundedCornerShape(12.dp)) { Text("Cancelar") }
                if (reservation.syncError.contains("Conflicto", ignoreCase = true)) {
                    OutlinedButton(onClick = onResolveConflict, shape = RoundedCornerShape(12.dp)) { Text("Resolver") }
                }
            }
        }
    }
}

@Composable
private fun ConflictResolverDialog(vm: MainViewModel, localUuid: String, onClose: () -> Unit) {
    var localRes by remember { mutableStateOf<ReservationEntity?>(null) }
    var localItems by remember { mutableStateOf<List<ReservationItemEntity>>(emptyList()) }
    var server by remember { mutableStateOf<ReservationWithItems?>(null) }

    var useServerClient by remember { mutableStateOf(false) }
    var useServerPhone by remember { mutableStateOf(false) }
    var useServerAddress by remember { mutableStateOf(false) }
    var useServerFecha by remember { mutableStateOf(false) }
    var useServerNotes by remember { mutableStateOf(false) }
    var useServerMetodo by remember { mutableStateOf(false) }
    var useServerEstado by remember { mutableStateOf(false) }
    var useServerAbono by remember { mutableStateOf(false) }
    var useServerCanal by remember { mutableStateOf(false) }
    var useServerItems by remember { mutableStateOf(false) }

    LaunchedEffect(localUuid) {
        val l = vm.loadReservation(localUuid)
        localRes = l
        localItems = vm.loadItems(localUuid)
        if (l?.remoteId != null) {
            server = vm.loadServerReservation(l.remoteId)
        }
    }

    val l = localRes
    val s = server?.reservation
    if (l == null || s == null) {
        AlertDialog(
            onDismissRequest = onClose,
            confirmButton = { Button(onClick = onClose) { Text("Cerrar") } },
            title = { Text("Resolver conflicto") },
            text = { Text("No se pudo cargar la version del servidor.") }
        )
        return
    }

    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = {
            Button(onClick = {
                val mergedClient = if (useServerClient) s.clientName else l.clientName
                val mergedPhone = if (useServerPhone) s.clientPhone else l.clientPhone
                val mergedAddress = if (useServerAddress) s.clientAddress else l.clientAddress
                val mergedFecha = if (useServerFecha) s.fechaReservaEpoch else l.fechaReservaEpoch
                val mergedNotes = if (useServerNotes) s.notes else l.notes
                val mergedMetodo = if (useServerMetodo) s.metodoPago else l.metodoPago
                val mergedEstado = if (useServerEstado) s.estadoReserva else l.estadoReserva
                val mergedAbono = if (useServerAbono) s.abono else l.abono
                val mergedCanal = if (useServerCanal) s.canalOrigen else l.canalOrigen
                val mergedItems = if (useServerItems) (server?.items ?: emptyList()) else localItems

                vm.saveReservation(
                    ReservationFormInput(
                        localUuid = l.localUuid,
                        remoteId = l.remoteId,
                        clientName = mergedClient,
                        clientPhone = mergedPhone,
                        clientAddress = mergedAddress,
                        clientRemoteId = l.clientRemoteId,
                        fechaReservaText = epochToText(mergedFecha),
                        notes = mergedNotes,
                        metodoPago = mergedMetodo,
                        canalOrigen = mergedCanal,
                        estadoPago = l.estadoPago,
                        estadoReserva = mergedEstado,
                        abono = mergedAbono,
                        costoMensajeria = l.costoMensajeria,
                        items = mergedItems,
                    )
                )
                onClose()
            }) { Text("Aplicar y reintentar") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancelar") } },
        title = { Text("Resolver conflicto campo por campo") },
        text = {
            Column(
                modifier = Modifier
                    .height(360.dp)
                    .verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                ConflictFieldToggle("Cliente", useServerClient) { useServerClient = it }
                ConflictFieldToggle("Telefono", useServerPhone) { useServerPhone = it }
                ConflictFieldToggle("Direccion", useServerAddress) { useServerAddress = it }
                ConflictFieldToggle("Fecha", useServerFecha) { useServerFecha = it }
                ConflictFieldToggle("Notas", useServerNotes) { useServerNotes = it }
                ConflictFieldToggle("Metodo", useServerMetodo) { useServerMetodo = it }
                ConflictFieldToggle("Estado", useServerEstado) { useServerEstado = it }
                ConflictFieldToggle("Abono", useServerAbono) { useServerAbono = it }
                ConflictFieldToggle("Canal", useServerCanal) { useServerCanal = it }
                ConflictFieldToggle("Items", useServerItems) { useServerItems = it }
                Text("Local: ${l.clientName} | Servidor: ${s.clientName}", style = MaterialTheme.typography.bodySmall)
            }
        }
    )
}

@Composable
private fun ConflictFieldToggle(label: String, useServer: Boolean, onChange: (Boolean) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, modifier = Modifier.width(90.dp))
        AppAssistChip(selected = !useServer, label = "Local") { onChange(false) }
        AppAssistChip(selected = useServer, label = "Servidor") { onChange(true) }
    }
}

@Composable
private fun CalendarLikeView(reservations: List<ReservationEntity>, onOpen: (String) -> Unit) {
    val byDay = reservations.groupBy { epochToText(it.fechaReservaEpoch).substring(0, 10) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        byDay.toSortedMap().forEach { (date, list) ->
            Card(shape = RoundedCornerShape(16.dp)) {
                Column(Modifier.fillMaxWidth().padding(12.dp)) {
                    Text(date, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(6.dp))
                    list.forEach { r ->
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .clickable { onOpen(r.localUuid) }
                                .padding(vertical = 4.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Box(
                                Modifier
                                    .size(8.dp)
                                    .background(
                                        when (r.estadoReserva) {
                                            "ENTREGADO" -> Color(0xFF22C55E)
                                            "CANCELADO" -> Color(0xFF94A3B8)
                                            else -> if (r.fechaReservaEpoch < System.currentTimeMillis()) Color(0xFFEF4444) else Color(0xFF3B82F6)
                                        },
                                        RoundedCornerShape(10.dp)
                                    )
                            )
                            Spacer(Modifier.width(8.dp))
                            Text("${r.clientName} - $${"%.2f".format(r.total)} (${r.estadoReserva})")
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun SettingsDialog(vm: MainViewModel, onClose: () -> Unit) {
    val baseUrl by vm.baseUrl.collectAsStateWithLifecycle()
    val apiKey by vm.apiKey.collectAsStateWithLifecycle()
    val sucursalId by vm.sucursalId.collectAsStateWithLifecycle()
    val otaJsonUrl by vm.otaJsonUrl.collectAsStateWithLifecycle()
    val scroll = rememberScrollState()

    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { Button(onClick = { vm.saveSettings(); onClose() }) { Text("Guardar") } },
        dismissButton = { TextButton(onClick = onClose) { Text("Cerrar") } },
        title = { Text("Servidor") },
        text = {
            Column(
                modifier = Modifier.verticalScroll(scroll),
                verticalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                OutlinedTextField(value = baseUrl, onValueChange = { vm.baseUrl.value = it }, label = { Text("Base URL") })
                OutlinedTextField(value = apiKey, onValueChange = { vm.apiKey.value = it }, label = { Text("API KEY") })
                OutlinedTextField(
                    value = sucursalId.toString(),
                    onValueChange = { vm.sucursalId.value = it.toIntOrNull() ?: 1 },
                    label = { Text("Sucursal ID") }
                )
                OutlinedTextField(
                    value = otaJsonUrl,
                    onValueChange = { vm.otaJsonUrl.value = it },
                    label = { Text("OTA JSON URL (opcional)") }
                )
                Text("Endpoint fijo: ${BuildConfig.DEFAULT_API_PATH}")
                Text("Nota: al cambiar sucursal, la base local usada sera la de esa sucursal.")
            }
        }
    )
}

@Composable
private fun HelpDialog(onClose: () -> Unit) {
    val scroll = rememberScrollState()
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { Button(onClick = onClose) { Text("Entendido") } },
        title = { Text("Ayuda de uso") },
        text = {
            Column(
                modifier = Modifier
                    .height(340.dp)
                    .verticalScroll(scroll),
                verticalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                Text("1) Configura Base URL, API KEY y Sucursal en Ajustes.")
                Text("2) Pulsa Descargar productos, clientes y reservaciones (mes actual y proximo).")
                Text("3) Crea/edita reservas sin internet. Se guardan localmente.")
                Text("4) Pulsa Sincronizar para subir pendientes al servidor.")
                Text("5) Revisa Historial Sync para errores y exporta CSV si necesitas soporte.")
                Text("6) Usa OTA para buscar y actualizar la app online.")
                Text("7) El autosync corre en segundo plano cuando hay internet.")
            }
        }
    )
}

@Composable
private fun DiagnosticDialog(report: String, onClose: () -> Unit) {
    val scroll = rememberScrollState()
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { Button(onClick = onClose) { Text("Cerrar") } },
        title = { Text("Reporte de diagnostico") },
        text = {
            Column(
                modifier = Modifier
                    .height(360.dp)
                    .verticalScroll(scroll),
                verticalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                Text(report, style = MaterialTheme.typography.bodySmall)
            }
        }
    )
}

@Composable
private fun StatusDialog(currentNote: String, onClose: () -> Unit, onSave: (String, String) -> Unit) {
    var estado by remember { mutableStateOf("PENDIENTE") }
    var nota by remember { mutableStateOf(currentNote) }
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { Button(onClick = { onSave(estado, nota) }) { Text("Guardar") } },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancelar") } },
        title = { Text("Gestionar estado") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("PENDIENTE", "EN_PREPARACION", "EN_CAMINO", "ENTREGADO", "CANCELADO").forEach {
                        AppAssistChip(selected = estado == it, label = it) { estado = it }
                    }
                }
                OutlinedTextField(value = nota, onValueChange = { nota = it }, label = { Text("Nota") })
            }
        }
    )
}

@Composable
private fun ReservationFormDialog(vm: MainViewModel, editingUuid: String?, onClose: () -> Unit) {
    val products by vm.products.collectAsStateWithLifecycle()
    val clients by vm.clients.collectAsStateWithLifecycle()

    var localUuid by remember { mutableStateOf<String?>(null) }
    var remoteId by remember { mutableStateOf<Long?>(null) }
    var clientName by remember { mutableStateOf("") }
    var clientPhone by remember { mutableStateOf("") }
    var clientAddress by remember { mutableStateOf("") }
    var clientRemoteId by remember { mutableStateOf<Long?>(null) }
    var fecha by remember { mutableStateOf(epochToText(System.currentTimeMillis() + 86_400_000L)) }
    var metodo by remember { mutableStateOf("Efectivo") }
    var canal by remember { mutableStateOf("WhatsApp") }
    var estadoPago by remember { mutableStateOf("pendiente") }
    var estadoReserva by remember { mutableStateOf("PENDIENTE") }
    var abono by remember { mutableStateOf("0") }
    var mensajeria by remember { mutableStateOf("0") }
    var notas by remember { mutableStateOf("") }
    var psearch by remember { mutableStateOf("") }
    var csearch by remember { mutableStateOf("") }
    val lines = remember { mutableStateListOf<ReservationItemEntity>() }

    LaunchedEffect(editingUuid) {
        if (editingUuid != null) {
            val row = vm.reservations.value.firstOrNull { it.localUuid == editingUuid } ?: return@LaunchedEffect
            localUuid = row.localUuid
            remoteId = row.remoteId
            clientName = row.clientName
            clientPhone = row.clientPhone
            clientAddress = row.clientAddress
            clientRemoteId = row.clientRemoteId
            fecha = epochToText(row.fechaReservaEpoch)
            metodo = row.metodoPago
            canal = row.canalOrigen
            estadoPago = row.estadoPago
            estadoReserva = row.estadoReserva
            abono = row.abono.toString()
            mensajeria = row.costoMensajeria.toString()
            notas = row.notes
            lines.clear()
            lines.addAll(withContext(Dispatchers.IO) { vm.loadItems(row.localUuid) })
        }
    }

    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = {
            Button(onClick = {
                if (clientName.isBlank() || lines.isEmpty()) return@Button
                vm.saveReservation(
                    ReservationFormInput(
                        localUuid = localUuid,
                        remoteId = remoteId,
                        clientName = clientName,
                        clientPhone = clientPhone,
                        clientAddress = clientAddress,
                        clientRemoteId = clientRemoteId,
                        fechaReservaText = fecha,
                        notes = notas,
                        metodoPago = metodo,
                        canalOrigen = canal,
                        estadoPago = estadoPago,
                        estadoReserva = estadoReserva,
                        abono = abono.toDoubleOrNull() ?: 0.0,
                        costoMensajeria = mensajeria.toDoubleOrNull() ?: 0.0,
                        items = lines.toList(),
                    )
                )
                onClose()
            }) { Text("Guardar") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancelar") } },
        title = { Text(if (editingUuid == null) "Nueva reserva" else "Editar reserva") },
        text = {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                item {
                    OutlinedTextField(value = clientName, onValueChange = { clientName = it }, label = { Text("Cliente") })
                    OutlinedTextField(value = clientPhone, onValueChange = { clientPhone = it }, label = { Text("Telefono") })
                    OutlinedTextField(value = clientAddress, onValueChange = { clientAddress = it }, label = { Text("Direccion") })
                    OutlinedTextField(value = fecha, onValueChange = { fecha = it }, label = { Text("Fecha yyyy-MM-dd HH:mm") })
                    OutlinedTextField(value = notas, onValueChange = { notas = it }, label = { Text("Notas") })
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        OutlinedTextField(value = abono, onValueChange = { abono = it }, label = { Text("Abono") }, modifier = Modifier.weight(1f))
                        OutlinedTextField(value = mensajeria, onValueChange = { mensajeria = it }, label = { Text("Mensajeria") }, modifier = Modifier.weight(1f))
                    }
                }
                item {
                    HorizontalDivider()
                    Text("Seleccionar cliente existente")
                    OutlinedTextField(value = csearch, onValueChange = { csearch = it; vm.clientSearch.value = it }, label = { Text("Buscar cliente") })
                    clients.take(8).forEach { c ->
                        Text(
                            "${c.name} (${c.phone})",
                            modifier = Modifier
                                .fillMaxWidth()
                                .clickable {
                                    clientName = c.name
                                    clientPhone = c.phone
                                    clientAddress = c.address
                                    clientRemoteId = c.remoteId
                                }
                                .padding(vertical = 3.dp)
                        )
                    }
                }
                item {
                    HorizontalDivider()
                    Text("Agregar productos")
                    OutlinedTextField(value = psearch, onValueChange = { psearch = it; vm.productSearch.value = it }, label = { Text("Buscar producto") })
                    products.take(12).forEach { p ->
                        Text(
                            "${p.name}  ${p.code}  $${"%.2f".format(p.price)}  stock:${"%.0f".format(p.stock)}",
                            modifier = Modifier
                                .fillMaxWidth()
                                .clickable {
                                    val idx = lines.indexOfFirst { it.productCode == p.code }
                                    if (idx >= 0) {
                                        val old = lines[idx]
                                        lines[idx] = old.copy(qty = old.qty + 1)
                                    } else {
                                        lines.add(
                                            ReservationItemEntity(
                                                reservationUuid = localUuid ?: "temp",
                                                productCode = p.code,
                                                productName = p.name,
                                                category = p.category,
                                                qty = 1.0,
                                                price = p.price,
                                                stockSnapshot = p.stock,
                                                esServicio = p.esServicio,
                                            )
                                        )
                                    }
                                }
                                .padding(vertical = 3.dp)
                        )
                    }
                }
                items(lines.size) { idx ->
                    val it = lines[idx]
                    Card(shape = RoundedCornerShape(12.dp)) {
                        Row(Modifier.fillMaxWidth().padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(it.productName, fontWeight = FontWeight.Bold)
                                Text("${it.productCode}  $${"%.2f".format(it.price)}")
                            }
                            OutlinedButton(onClick = { if (it.qty > 1.0) lines[idx] = it.copy(qty = it.qty - 1) }) { Text("-") }
                            Text(" ${"%.0f".format(it.qty)} ")
                            OutlinedButton(onClick = { lines[idx] = it.copy(qty = it.qty + 1) }) { Text("+") }
                        }
                    }
                }
                item {
                    val subtotal = lines.sumOf { it.qty * it.price }
                    val total = subtotal + (mensajeria.toDoubleOrNull() ?: 0.0)
                    Text("Subtotal: $${"%.2f".format(subtotal)}")
                    Text("Total: $${"%.2f".format(total)}", fontWeight = FontWeight.Bold)
                }
            }
        }
    )
}

private fun sameDay(a: Long, b: Long): Boolean {
    val da = epochToText(a).substring(0, 10)
    val db = epochToText(b).substring(0, 10)
    return da == db
}
