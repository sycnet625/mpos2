<?php
// ARCHIVO: palweb_ai.php
// DESCRIPCIÓN: Controlador Avanzado de Agentes IA (PWA, Mobile-First, VAPID, Whisper)
// VERSIÓN: 2.2 - Proyectos & CRUD

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'config_loader.php';
$vapid_public = $config['vapid_public_key'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="manifest-ai.json">
    <title>AI Control Center</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="assets/js/vue.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root {
            --bg-deep: #0b0e11;
            --bg-card: #1e2327;
            --bg-accent: #10a37f;
            --text-main: #e3e6e8;
            --text-muted: #9ba3af;
            --border: rgba(255,255,255,0.1);
        }
        body { background: var(--bg-deep); color: var(--text-main); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; height: 100vh; margin: 0; overflow: hidden; }
        #app { display: flex; flex-direction: column; height: 100vh; }

        /* Header */
        .ai-header { padding: 12px 20px; background: var(--bg-card); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; z-index: 100; }
        .ai-brand { font-weight: 800; font-size: 1.1rem; color: var(--bg-accent); display: flex; align-items: center; gap: 8px; }

        /* Sidebar Layout (ChatGPT Style) */
        .sidebar-layout { display: flex; flex-direction: row !important; }
        .sidebar { 
            width: 260px; 
            background: #171717; 
            border-right: 1px solid var(--border); 
            display: flex; 
            flex-direction: column; 
            height: calc(100vh - 56px); 
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }
        .sidebar-header { padding: 15px; }
        .new-chat-btn { 
            width: 100%; 
            background: transparent; 
            border: 1px solid var(--border); 
            color: white; 
            border-radius: 8px; 
            padding: 10px; 
            text-align: left; 
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background 0.2s;
        }
        .new-chat-btn:hover { background: rgba(255,255,255,0.05); }
        .sidebar-history { flex-grow: 1; overflow-y: auto; padding: 10px; }
        .history-item { 
            padding: 10px; 
            border-radius: 8px; 
            margin-bottom: 5px; 
            cursor: pointer; 
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #d1d1d1;
        }
        .history-item:hover { background: #2a2a2a; }
        .history-item.active { background: #212121; color: white; }
        .history-item i { font-size: 0.8rem; opacity: 0.6; }

        .focus-chat-container { 
            flex-grow: 1; 
            display: flex; 
            flex-direction: column; 
            height: calc(100vh - 56px); 
            background: var(--bg-deep);
            position: relative;
        }
        .focus-chat-container .agent-card {
            height: 100%;
            border: none;
            border-radius: 0;
            background: transparent;
        }
        .focus-chat-container .card-body-chat {
            padding: 20px 15% 40px 15%;
        }
        @media (max-width: 1200px) {
            .focus-chat-container .card-body-chat { padding: 20px 5% 40px 5%; }
        }

        @media (max-width: 768px) {
            .sidebar { 
                position: fixed; 
                left: 0; 
                top: 56px; 
                z-index: 1000; 
                height: calc(100vh - 56px);
                transform: translateX(-100%);
            }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay {
                position: fixed;
                top: 56px;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
                display: none;
            }
            .sidebar-overlay.show { display: block; }
            .focus-chat-container .card-body-chat { padding: 15px; }
        }

        /* Adjust existing Dashboard */
        .dashboard-grid { 
            flex-grow: 1; 
            overflow-y: auto; 
            padding: 15px; 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); 
            gap: 15px; 
            padding-bottom: 100px; 
        }
        
        /* Mobile Specifics */
        @media (max-width: 768px) {
            body { position: fixed; width: 100%; height: 100%; }
            .ai-header { padding: 8px 12px; height: 50px; }
            #app { height: 100dvh; }
            .dashboard-grid { 
                height: calc(100dvh - 50px - 60px); 
            }
            .sidebar-layout {
                height: calc(100dvh - 50px);
            }
            .agent-card { 
                height: 100% !important;
            }
            .card-body-chat { padding-bottom: 30px; }
            
            /* Adjust input for mobile */
            .card-footer-input { 
                padding: 10px 12px;
                padding-bottom: calc(15px + env(safe-area-inset-bottom, 0px)); 
                margin-bottom: 2px;
            }
            .input-text { font-size: 16px !important; padding: 10px 15px; }
            .mobile-tabs { height: 60px; }
        }

        /* Status Bar */
        .chat-status-bar {
            padding: 6px 15px;
            background: rgba(0,0,0,0.3);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .usage-circle {
            position: relative;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .usage-circle svg {
            transform: rotate(-90deg);
            width: 24px;
            height: 24px;
        }
        .usage-circle circle {
            fill: none;
            stroke-width: 3;
        }
        .usage-circle .bg { stroke: rgba(255,255,255,0.1); }
        .usage-circle .progress {
            stroke: var(--bg-accent);
            stroke-linecap: round;
            transition: stroke-dashoffset 0.5s ease;
        }
        .usage-text { font-weight: 700; color: var(--text-main); margin-left: 8px; font-family: monospace; }
        .model-badge {
            background: rgba(16, 103, 127, 0.2);
            color: #4fd1c5;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.65rem;
            letter-spacing: 0.5px;
        }

        /* Agent Card */
        .agent-card { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border); display: flex; flex-direction: column; height: 450px; transition: 0.3s; position: relative; overflow: hidden; }
        .agent-card.active { border-color: var(--bg-accent); box-shadow: 0 0 20px rgba(16, 163, 127, 0.2); }
        .card-head { padding: 12px 15px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.2); }
        .card-body-chat { flex-grow: 1; overflow-y: auto; padding: 15px; display: flex; flex-direction: column; gap: 12px; scroll-behavior: smooth; }
        
        /* Messages */
        .msg { max-width: 85%; padding: 10px 14px; border-radius: 12px; font-size: 0.95rem; line-height: 1.5; position: relative; }
        .msg.user { align-self: flex-end; background: var(--bg-accent); color: white; border-bottom-right-radius: 2px; }
        .msg.assistant { align-self: flex-start; background: #2d3339; border-bottom-left-radius: 2px; }
        .msg pre { background: #000; padding: 10px; border-radius: 6px; font-size: 0.8rem; color: #7ee787; margin-top: 8px; overflow-x: auto; }

        /* Controls Area */
        .card-footer-input { padding: 20px; border-top: 1px solid var(--border); background: rgba(0,0,0,0.2); }
        .input-group-custom { display: flex; gap: 12px; align-items: flex-end; max-width: 1000px; margin: 0 auto; }
        .input-text { 
            flex-grow: 1; 
            background: #2d3339; 
            border: 1px solid var(--border); 
            border-radius: 26px; 
            color: white; 
            padding: 12px 20px; 
            outline: none; 
            font-size: 1rem; 
            max-height: 200px; 
            resize: none; 
            line-height: 1.4;
        }
        .action-btn { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; transition: 0.2s; background: transparent; color: var(--text-muted); }
        .action-btn:hover { color: white; background: rgba(255,255,255,0.1); }
        .action-btn.send { background: var(--bg-accent); color: white; flex-shrink: 0; }

        /* Status Badges */
        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-dot.online { background: #10a37f; box-shadow: 0 0 8px #10a37f; }
        .status-dot.working { background: #f59e0b; animation: pulse 1s infinite; }

        /* Premium Modal SaaS Style */
        .modal-content-premium { background: #1e2327; border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; overflow: hidden; }
        .agent-option { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; align-items: center; gap: 15px; margin-bottom: 12px; }
        .agent-option:hover { background: rgba(16, 163, 127, 0.1); border-color: #10a37f; transform: translateY(-2px); }
        .agent-option.active { background: rgba(16, 163, 127, 0.2); border-color: #10a37f; box-shadow: 0 0 20px rgba(16, 163, 127, 0.1); }
        .agent-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
        .claude-icon { background: linear-gradient(135deg, #d97757, #9a3412); color: white; }
        .opencode-icon { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .codex-icon { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; }
        .kilo-icon { background: linear-gradient(135deg, #10a37f, #065f46); color: white; }
        .agent-info h6 { margin: 0; font-weight: 700; color: white; }
        .agent-info p { margin: 0; font-size: 0.8rem; color: #9ba3af; }

        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

        [v-cloak] { display: none; }
    </style>
</head>
<body>

<div id="app" v-cloak>
    <header class="ai-header">
        <div class="ai-brand">
            <button class="action-btn d-md-none me-2" v-if="layoutMode === 'sidebar'" @click="showMobileSidebar = !showMobileSidebar">
                <i class="fas fa-bars"></i>
            </button>
            <i class="fas fa-brain"></i> <span>PalWeb AI</span>
        </div>
        <div class="d-flex align-items-center gap-2 me-auto ms-4 d-none d-md-flex">
            <div class="btn-group btn-group-sm bg-dark p-1 rounded-pill border border-secondary">
                <button class="btn btn-sm rounded-pill px-3" :class="selectedMode === 'chat' ? 'btn-success' : 'btn-dark text-muted'" @click="selectedMode = 'chat'">
                    <i class="fas fa-comment-dots me-1"></i> Chat
                </button>
                <button class="btn btn-sm rounded-pill px-3" :class="selectedMode === 'terminal' ? 'btn-warning text-dark' : 'btn-dark text-muted'" @click="selectedMode = 'terminal'">
                    <i class="fas fa-terminal me-1"></i> Terminal
                </button>
            </div>
            <div class="btn-group btn-group-sm bg-dark p-1 rounded-pill border border-secondary ms-3">
                <button class="btn btn-sm rounded-pill px-3" :class="layoutMode === 'grid' ? 'btn-info text-dark' : 'btn-dark text-muted'" @click="layoutMode = 'grid'">
                    <i class="fas fa-th-large me-1"></i> Grid
                </button>
                <button class="btn btn-sm rounded-pill px-3" :class="layoutMode === 'sidebar' ? 'btn-info text-dark' : 'btn-dark text-muted'" @click="layoutMode = 'sidebar'">
                    <i class="fas fa-columns me-1"></i> Focus
                </button>
            </div>
        </div>
        <div class="d-flex gap-3">
            <button class="action-btn" @click="viewServerLogs" title="Ver Logs del Servidor">
                <i class="fas fa-file-alt"></i>
            </button>
            <button class="action-btn" @click="toggleNotifications" :title="notificationsActive ? 'Push Activo' : 'Activar Push'">
                <i class="fas" :class="notificationsActive ? 'fa-bell text-success' : 'fa-bell-slash'"></i>
            </button>
            <button class="action-btn" onclick="location.href='dashboard.php'"><i class="fas fa-home"></i></button>
        </div>
    </header>

    <main :class="{'dashboard-grid': layoutMode === 'grid', 'sidebar-layout': layoutMode === 'sidebar'}">
        
        <!-- Sidebar para Modo Focus -->
        <template v-if="layoutMode === 'sidebar'">
            <div class="sidebar-overlay" :class="{show: showMobileSidebar}" @click="showMobileSidebar = false"></div>
            <aside class="sidebar" :class="{show: showMobileSidebar}">
                <div class="sidebar-header">
                    <button class="new-chat-btn" @click="createNewSession">
                        <i class="fas fa-plus"></i>
                        <span>Nuevo Chat</span>
                    </button>
                </div>
                <div class="sidebar-history">
                    <div v-for="s in sessions" :key="'side-'+s.id" 
                         class="history-item" 
                         :class="{active: mobileActiveSessionId === s.id}"
                         @click="mobileActiveSessionId = s.id; showMobileSidebar = false">
                        <i class="fas" :class="s.loading ? 'fa-circle-notch fa-spin text-warning' : 'fa-comment-alt'"></i>
                        <span>{{ s.titulo }}</span>
                    </div>
                </div>
            </aside>
            <div class="focus-chat-container">
                <template v-for="session in sessions">
                    <div v-if="mobileActiveSessionId === session.id" class="agent-card mobile-active" :key="'focus-'+session.id">
                        <div class="card-head">
                            <div class="d-flex align-items-center">
                                <span class="status-dot" :class="session.loading ? 'working' : 'online'"></span>
                                <div class="d-flex flex-column" style="line-height: 1.1;">
                                    <strong class="small">{{ session.titulo }}</strong>
                                    <small class="text-muted" style="font-size: 0.6rem;">{{ session.proyecto_nombre || 'Principal' }}</small>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="action-btn btn-sm" @click="openTerminalView(session)" title="Ver Terminal Real"><i class="fas fa-terminal"></i></button>
                                <button class="action-btn btn-sm" @click="attachFile(session.id)" title="Subir Archivo"><i class="fas fa-paperclip"></i></button>
                                <button class="action-btn btn-sm" @click="closeSession(session.id)" title="Cerrar"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <div class="card-body-chat" :id="'chat-box-focus-' + session.id">
                            <div v-if="session.messages.length === 0" class="text-center mt-5 opacity-25">
                                <i class="fas fa-terminal fa-3x mb-3"></i>
                                <p class="small">Sesión de terminal activa</p>
                            </div>
                            <div v-for="(m, idx) in session.messages" :key="idx" class="msg" :class="m.rol">
                                <div v-html="renderMarkdown(m.contenido)"></div>
                            </div>
                            <div v-if="session.loading" class="msg assistant">
                                <i class="fas fa-circle-notch fa-spin"></i> Procesando...
                            </div>
                            <div v-if="session.awaitingApproval" class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background: rgba(0,0,0,0.7); z-index: 10;">
                                <div class="text-center p-4 bg-dark border border-warning rounded-3 shadow-lg">
                                    <i class="fas fa-exclamation-triangle text-warning fa-2x mb-3"></i>
                                    <h6 class="text-white">¿Aprobar acción del agente?</h6>
                                    <div class="d-flex gap-2 mt-3">
                                        <button class="btn btn-success fw-bold" @click="quickApprove(session, 'y')">SI (y)</button>
                                        <button class="btn btn-danger fw-bold" @click="quickApprove(session, 'n')">NO (n)</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer-input">
                            <div class="input-group-custom">
                                <button class="action-btn" @click="startVoice(session.id)" :class="{'text-danger': recording}">
                                    <i class="fas fa-microphone"></i>
                                </button>
                                <textarea class="input-text" rows="2" v-model="session.input" placeholder="Comando..." @keydown.enter.prevent="sendToAgent(session)"></textarea>
                                <button class="action-btn send" :disabled="session.loading || !session.input.trim()" @click="sendToAgent(session)"><i class="fas fa-arrow-up"></i></button>
                            </div>
                        </div>
                        <div class="chat-status-bar">
                            <span class="model-badge">{{ session.agente }}</span>
                            <div class="d-flex align-items-center">
                                <div class="usage-circle">
                                    <svg viewBox="0 0 24 24">
                                        <circle class="bg" cx="12" cy="12" r="10"></circle>
                                        <circle class="progress" cx="12" cy="12" r="10" 
                                            :style="{'stroke-dasharray': 62.8, 'stroke-dashoffset': 62.8 * (1 - (session.usage_pct / 100))}"></circle>
                                    </svg>
                                </div>
                                <span class="usage-text">#{{ session.usage_count }} ({{ session.usage_pct }}%)</span>
                            </div>
                        </div>
                    </div>
                </template>
                <div v-if="sessions.length === 0" class="text-center mt-5">
                    <button class="btn btn-outline-success" @click="createNewSession">
                        <i class="fas fa-plus me-2"></i> Comenzar nuevo chat
                    </button>
                </div>
            </div>
        </template>

        <!-- Modo Grid (Actual) -->
        <template v-if="layoutMode === 'grid'">
            <!-- Tarjeta de Agente (Instancia de Chat) -->
            <div v-for="session in sessions" :key="session.id" class="agent-card" :class="{active: activeSessionId === session.id, 'mobile-active': mobileActiveSessionId === session.id}">
                <div class="card-head">
                    <div class="d-flex align-items-center">
                        <span class="status-dot" :class="session.loading ? 'working' : 'online'"></span>
                        <div class="d-flex flex-column" style="line-height: 1.1;">
                            <strong class="small">{{ session.titulo }}</strong>
                            <small class="text-muted" style="font-size: 0.6rem;">{{ session.proyecto_nombre || 'Principal' }}</small>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="action-btn btn-sm" @click="openTerminalView(session)" title="Ver Terminal Real"><i class="fas fa-terminal"></i></button>
                        <button class="action-btn btn-sm" @click="attachFile(session.id)" title="Subir Archivo"><i class="fas fa-paperclip"></i></button>
                        <button class="action-btn btn-sm" @click="closeSession(session.id)" title="Cerrar"><i class="fas fa-times"></i></button>
                    </div>
                </div>

                <div class="card-body-chat" :id="'chat-box-' + session.id">
                    <div v-if="session.messages.length === 0" class="text-center mt-5 opacity-25">
                        <i class="fas fa-terminal fa-3x mb-3"></i>
                        <p class="small">Sesión de terminal activa</p>
                    </div>
                    <div v-for="(m, idx) in session.messages" :key="idx" class="msg" :class="m.rol">
                        <div v-html="renderMarkdown(m.contenido)"></div>
                    </div>
                    <div v-if="session.loading" class="msg assistant">
                        <i class="fas fa-circle-notch fa-spin"></i> Procesando...
                    </div>

                    <!-- Overlay de Aprobación -->
                    <div v-if="session.awaitingApproval" class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background: rgba(0,0,0,0.7); z-index: 10;">
                        <div class="text-center p-4 bg-dark border border-warning rounded-3 shadow-lg">
                            <i class="fas fa-exclamation-triangle text-warning fa-2x mb-3"></i>
                            <h6 class="text-white">¿Aprobar acción del agente?</h6>
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-success fw-bold" @click="quickApprove(session, 'y')">SI (y)</button>
                                <button class="btn btn-danger fw-bold" @click="quickApprove(session, 'n')">NO (n)</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer-input">
                    <div class="input-group-custom">
                        <button class="action-btn" @click="startVoice(session.id)" :class="{'text-danger': recording}">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <textarea 
                            class="input-text" 
                            rows="2" 
                            v-model="session.input" 
                            placeholder="Comando..."
                            @keydown.enter.prevent="sendToAgent(session)"
                        ></textarea>
                        <button class="action-btn send" :disabled="session.loading || !session.input.trim()" @click="sendToAgent(session)">
                            <i class="fas fa-arrow-up"></i>
                        </button>
                    </div>
                </div>

                <div class="chat-status-bar">
                    <span class="model-badge">{{ session.agente }}</span>
                    <div class="d-flex align-items-center">
                        <div class="usage-circle">
                            <svg viewBox="0 0 24 24">
                                <circle class="bg" cx="12" cy="12" r="10"></circle>
                                <circle class="progress" cx="12" cy="12" r="10" 
                                    :style="{'stroke-dasharray': 62.8, 'stroke-dashoffset': 62.8 * (1 - (session.usage_pct / 100))}"></circle>
                            </svg>
                        </div>
                        <span class="usage-text">#{{ session.usage_count }} ({{ session.usage_pct }}%)</span>
                    </div>
                </div>
            </div>

            <!-- Botón para nueva sesión en el grid -->
            <div class="agent-card d-flex align-items-center justify-content-center border-dashed opacity-50 d-none d-md-flex" style="border-style: dashed; cursor: pointer;" @click="createNewSession">
                <div class="text-center">
                    <i class="fas fa-plus-circle fa-2x mb-2"></i>
                    <div class="small fw-bold">NUEVA SESIÓN</div>
                </div>
            </div>

            <!-- Navegación de Pestañas en Móvil (Solo en modo Grid) -->
            <div class="mobile-tabs d-flex d-md-none">
                <button v-for="s in sessions" :key="'tab-'+s.id" 
                        class="mobile-tab-btn" 
                        :class="{active: mobileActiveSessionId === s.id}"
                        @click="mobileActiveSessionId = s.id">
                    <i class="fas" :class="s.loading ? 'fa-circle-notch fa-spin text-warning' : 'fa-terminal'"></i>
                    <span class="text-truncate" style="max-width: 90px;">{{ s.titulo }}</span>
                </button>
                <button class="mobile-tab-btn add-btn" @click="createNewSession">
                    <i class="fas fa-plus"></i>
                    <span>Nuevo</span>
                </button>
            </div>
        </template>
    </main>

    <!-- Overlay para Terminal o Logs -->
    <div v-if="overlay.show" class="position-fixed top-0 start-0 w-100 h-100 d-flex flex-column bg-black" style="z-index: 10000;">
        <div class="p-3 d-flex justify-content-between align-items-center border-bottom border-secondary">
            <h6 class="m-0 text-white"><i class="fas fa-terminal me-2"></i> {{ overlay.title }}</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-light" @click="overlay.refresh()"><i class="fas fa-sync"></i></button>
                <button class="btn btn-sm btn-danger" @click="overlay.show = false"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <pre class="flex-grow-1 m-0 p-3 overflow-auto text-success small" style="background: #000; font-family: 'Courier New', monospace;">{{ overlay.content }}</pre>
    </div>

    <!-- Modal Invisible para Archivos -->
    <input type="file" id="fileInput" class="d-none" @change="handleFileUpload">

    <!-- PREMIUM AGENT SELECTOR MODAL -->
    <div class="modal fade" id="agentSelectorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium shadow-lg">
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-circle mb-3" style="width:64px; height:64px; font-size:1.5rem;">
                            <i class="fas fa-plus"></i>
                        </div>
                        <h4 class="fw-bold text-white mb-1">Nueva Sesión de IA</h4>
                        <p class="text-muted small">Selecciona el agente especializado para tu proyecto</p>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">PROYECTO / RUTA DE TRABAJO</label>
                        <div class="d-flex gap-2">
                            <select class="form-select bg-dark text-white border-secondary rounded-pill px-3" v-model="selectedProjectId">
                                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.nombre }} ({{ p.ruta }})</option>
                            </select>
                            <button class="btn btn-outline-secondary rounded-pill" @click="editProject({id:null, nombre:'', ruta:'/var/www'})" title="Configurar Proyectos">
                                <i class="fas fa-cog"></i>
                            </button>
                        </div>
                    </div>

                    <div class="agent-options">
                        <div class="agent-option" :class="{active: selectedForNew === 'claude'}" @click="selectedForNew = 'claude'">
                            <div class="agent-icon claude-icon">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div class="agent-info">
                                <h6>Claude Code</h6>
                                <p>Ingeniería de software y ejecución de herramientas.</p>
                            </div>
                            <div class="ms-auto" v-if="selectedForNew === 'claude'">
                                <i class="fas fa-check-circle text-success fa-lg"></i>
                            </div>
                        </div>

                        <div class="agent-option" :class="{active: selectedForNew === 'opencode'}" @click="selectedForNew = 'opencode'">
                            <div class="agent-icon opencode-icon">
                                <i class="fas fa-code"></i>
                            </div>
                            <div class="agent-info">
                                <h6>OpenCode</h6>
                                <p>Modelos locales y código abierto.</p>
                            </div>
                            <div class="ms-auto" v-if="selectedForNew === 'opencode'">
                                <i class="fas fa-check-circle text-success fa-lg"></i>
                            </div>
                        </div>

                        <div class="agent-option" :class="{active: selectedForNew === 'codex'}" @click="selectedForNew = 'codex'">
                            <div class="agent-icon codex-icon">
                                <i class="fas fa-microchip"></i>
                            </div>
                            <div class="agent-info">
                                <h6>Codex</h6>
                                <p>Análisis de código y automatización avanzada.</p>
                            </div>
                            <div class="ms-auto" v-if="selectedForNew === 'codex'">
                                <i class="fas fa-check-circle text-success fa-lg"></i>
                            </div>
                        </div>

                        <div class="agent-option" :class="{active: selectedForNew === 'kilo'}" @click="selectedForNew = 'kilo'">
                            <div class="agent-icon kilo-icon">
                                <i class="fas fa-brain"></i>
                            </div>
                            <div class="agent-info">
                                <h6>Kilo</h6>
                                <p>Agente kilo especializado.</p>
                            </div>
                            <div class="ms-auto" v-if="selectedForNew === 'kilo'">
                                <i class="fas fa-check-circle text-success fa-lg"></i>
                            </div>
                        </div>

                        <div class="agent-option" :class="{active: selectedForNew === 'kilo-code'}" @click="selectedForNew = 'kilo-code'">
                            <div class="agent-icon kilo-icon" style="background: linear-gradient(135deg, #059669, #064e3b);">
                                <i class="fas fa-microchip"></i>
                            </div>
                            <div class="agent-info">
                                <h6>Kilo Code</h6>
                                <p>Ingeniería de software avanzada con Kilo.</p>
                            </div>
                            <div class="ms-auto" v-if="selectedForNew === 'kilo-code'">
                                <i class="fas fa-check-circle text-success fa-lg"></i>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-2">
                        <button class="btn btn-success w-100 py-3 fw-bold rounded-pill shadow-lg" 
                                @click="confirmNewSession" 
                                :disabled="!selectedForNew || !selectedProjectId">
                            INICIAR AGENTE
                        </button>
                        <button class="btn btn-link w-100 text-muted small mt-2 text-decoration-none" data-bs-dismiss="modal">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PROJECT MANAGEMENT MODAL -->
    <div class="modal fade" id="projectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium shadow-lg">
                <div class="modal-body p-4 text-white">
                    <h5 class="fw-bold mb-4">Gestionar Proyectos</h5>
                    
                    <div class="card bg-dark border-secondary p-3 mb-4">
                        <h6 class="small fw-bold text-muted mb-3">{{ editingProject.id ? 'EDITAR PROYECTO' : 'NUEVO PROYECTO' }}</h6>
                        <div class="mb-3">
                            <label class="small text-muted">Nombre</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" v-model="editingProject.nombre" placeholder="Ej: eCommerce API">
                        </div>
                        <div class="mb-3">
                            <label class="small text-muted">Ruta Absoluta en Servidor</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" v-model="editingProject.ruta" placeholder="/var/www/...">
                        </div>
                        <button class="btn btn-success btn-sm w-100 fw-bold" @click="saveProject">
                            {{ editingProject.id ? 'ACTUALIZAR' : 'CREAR PROYECTO' }}
                        </button>
                    </div>

                    <div class="project-list" style="max-height: 200px; overflow-y: auto;">
                        <div v-for="p in projects" :key="p.id" class="d-flex justify-content-between align-items-center p-2 border-bottom border-secondary border-opacity-50">
                            <div>
                                <div class="small fw-bold">{{ p.nombre }}</div>
                                <div class="text-muted" style="font-size: 0.7rem;">{{ p.ruta }}</div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-link btn-sm text-info p-0" @click="editProject(p)"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" @click="deleteProject(p.id)"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-4">
                        <button class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Debug Console (Opcional, flotante) -->
    <div v-if="showDebug" class="position-fixed bottom-0 start-0 w-100 bg-black p-2 font-monospace" style="font-size: 10px; height: 100px; overflow-y: auto; z-index: 1000; opacity: 0.8;">
        <div v-for="l in debugLogs" class="text-success border-bottom border-secondary">{{ l.time }} - {{ l.msg }}</div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const VAPID_PUBLIC = '<?php echo $vapid_public; ?>';

new Vue({
    el: '#app',
    data: {
        sessions: [],
        activeSessionId: null,
        mobileActiveSessionId: null,
        layoutMode: localStorage.getItem('palweb_ai_layout') || 'sidebar',
        showMobileSidebar: false,
        debugLogs: [],
        showDebug: false,
        recording: false,
        notificationsActive: false,
        swRegistration: null,
        selectedMode: 'chat', 
        selectedForNew: 'claude',
        selectedProjectId: null,
        projects: [],
        editingProject: { id: null, nombre: '', ruta: '/var/www' },
        overlay: {
            show: false,
            title: '',
            content: 'Cargando...',
            refresh: () => {}
        }
    },
    async mounted() {
        this.log("Iniciando Dashboard Multisesión...");
        await this.loadProjects();
        await this.loadActiveSessions();
        this.initPWA();
        
        setInterval(() => {
            this.sessions.forEach(s => {
                if (!s.loading) this.refreshMessages(s.id);
            });
        }, 5000);
    },
    watch: {
        layoutMode(newVal) {
            localStorage.setItem('palweb_ai_layout', newVal);
        },
        mobileActiveSessionId() {
            if (this.layoutMode === 'sidebar') {
                this.$nextTick(() => this.scrollToBottom(this.mobileActiveSessionId));
            }
        }
    },
    methods: {
        log(msg) {
            const time = new Date().toLocaleTimeString();
            this.debugLogs.push({ time, msg });
            console.log(`[AI] ${msg}`);
        },
        async loadProjects() {
            const res = await fetch('palweb_ai_api.php?action=list_projects');
            this.projects = await res.json();
            if (this.projects.length > 0 && !this.selectedProjectId) {
                this.selectedProjectId = this.projects[0].id;
            }
        },
        async saveProject() {
            if (!this.editingProject.nombre || !this.editingProject.ruta) return;
            await fetch('palweb_ai_api.php?action=save_project', {
                method: 'POST',
                body: JSON.stringify(this.editingProject)
            });
            this.editingProject = { id: null, nombre: '', ruta: '/var/www' };
            await this.loadProjects();
            const modalEl = document.getElementById('projectModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        },
        async deleteProject(id) {
            if (!confirm("¿Borrar este proyecto?")) return;
            await fetch(`palweb_ai_api.php?action=delete_project&id=${id}`);
            await this.loadProjects();
        },
        editProject(p) {
            this.editingProject = { ...p };
            const modal = new bootstrap.Modal(document.getElementById('projectModal'));
            modal.show();
        },
        async loadActiveSessions() {
            this.log("Recuperando sesiones de la base de datos...");
            const res = await fetch('palweb_ai_api.php?action=list_chats');
            const chats = await res.json();
            
            this.sessions = chats.map(c => ({
                id: c.id,
                titulo: c.titulo,
                agente: c.agente,
                proyecto_nombre: c.proyecto_nombre,
                messages: [],
                input: '',
                loading: false,
                awaitingApproval: false,
                usage_count: 0,
                usage_pct: 0
            }));

            if (this.sessions.length > 0 && !this.mobileActiveSessionId) {
                this.mobileActiveSessionId = this.sessions[0].id;
            }

            for (let s of this.sessions) {
                this.refreshMessages(s.id);
            }
        },
        async createNewSession() {
            this.selectedForNew = 'claude';
            const modal = new bootstrap.Modal(document.getElementById('agentSelectorModal'));
            modal.show();
        },
        async confirmNewSession() {
            const agent = this.selectedForNew;
            if (!agent || !this.selectedProjectId) return;
            
            const res = await fetch('palweb_ai_api.php?action=new_chat', {
                method: 'POST',
                body: JSON.stringify({ 
                    agente: agent, 
                    titulo: 'Sesión ' + agent,
                    project_id: this.selectedProjectId
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                const proj = this.projects.find(p => p.id == this.selectedProjectId);
                this.sessions.push({
                    id: data.id,
                    titulo: 'Sesión ' + agent,
                    proyecto_nombre: proj ? proj.nombre : '',
                    agente: agent,
                    messages: [],
                    input: '',
                    loading: false,
                    awaitingApproval: false,
                    usage_count: 0,
                    usage_pct: 0
                });
                this.mobileActiveSessionId = data.id;
                this.log(`Nueva sesión creada: ${data.id}`);
                
                const modalEl = document.getElementById('agentSelectorModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
        },
        async refreshMessages(sessionId, force = false) {
            const session = this.sessions.find(s => s.id === sessionId);
            if (!session) return;

            // Detectar si el usuario está al final antes de actualizar
            const elId = this.layoutMode === 'sidebar' ? 'chat-box-focus-' + sessionId : 'chat-box-' + sessionId;
            const el = document.getElementById(elId);
            const wasAtBottom = el ? (el.scrollHeight - el.scrollTop - el.clientHeight < 100) : true;

            const res = await fetch(`palweb_ai_api.php?action=get_messages&chat_id=${sessionId}`);
            const msgs = await res.json();
            
            // Si no hay mensajes nuevos y no es forzado, no hacemos nada pesado
            if (msgs.length === session.messages.length && !force) return;

            session.messages = msgs;
            session.usage_count = msgs.length;
            session.usage_pct = Math.min(100, Math.round((msgs.length / 50) * 100));
            
            // Solo scrollear si estaba al final o si se fuerza (ej: al enviar mensaje)
            if (force || wasAtBottom) {
                this.scrollToBottom(sessionId);
            }
            
            if (this.selectedMode === 'terminal') {
                this.checkApprovalState(session);
            } else {
                session.awaitingApproval = false;
            }
        },
        async checkApprovalState(session) {
            try {
                const res = await fetch(`palweb_ai_api.php?action=check_status&chat_id=${session.id}&agente=${session.agente}`);
                const data = await res.json();
                session.awaitingApproval = data.awaiting_approval;
            } catch (e) {}
        },
        async quickApprove(session, response) {
            session.loading = true;
            await fetch('palweb_ai_api.php?action=approve_action', {
                method: 'POST',
                body: JSON.stringify({ chat_id: session.id, agente: session.agente, response: response })
            });
            session.awaitingApproval = false;
            setTimeout(() => this.refreshMessages(session.id, true), 1000);
        },
        async openTerminalView(session) {
            this.overlay.title = `Terminal: ${session.titulo}`;
            this.overlay.show = true;
            this.overlay.content = 'Cargando terminal...';
            this.overlay.refresh = async () => {
                const res = await fetch(`palweb_ai_api.php?action=get_terminal_view&chat_id=${session.id}&agente=${session.agente}`);
                const data = await res.json();
                this.overlay.content = data.data || 'No se pudo capturar la terminal.';
            };
            this.overlay.refresh();
        },
        async viewServerLogs() {
            this.overlay.title = 'Logs del Servidor (AI Debug)';
            this.overlay.show = true;
            this.overlay.content = 'Cargando logs...';
            this.overlay.refresh = async () => {
                const res = await fetch('palweb_ai_api.php?action=get_raw_logs');
                const data = await res.json();
                this.overlay.content = data.data || 'Sin logs registrados.';
            };
            this.overlay.refresh();
        },
        async sendToAgent(session) {
            if (session.loading || !session.input.trim()) return;
            
            const msgText = session.input;
            session.input = '';
            session.loading = true;
            this.log(`[Session ${session.id}] Enviando comando en modo ${this.selectedMode}...`);

            session.messages.push({ rol: 'user', contenido: msgText });
            this.scrollToBottom(session.id);

            try {
                const res = await fetch('palweb_ai_api.php?action=send_message', {
                    method: 'POST',
                    body: JSON.stringify({ 
                        chat_id: session.id, 
                        message: msgText,
                        agente: session.agente,
                        mode: this.selectedMode
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    this.refreshMessages(session.id, true);
                } else {
                    alert("Error API: " + data.msg);
                }
            } catch (e) {
                this.log("Error de conexión");
            } finally {
                session.loading = false;
            }
        },
        async closeSession(id) {
            if (!confirm("¿Cerrar sesión? El proceso en tmux se detendrá.")) return;
            await fetch(`palweb_ai_api.php?action=delete_chat&chat_id=${id}`);
            this.sessions = this.sessions.filter(s => s.id !== id);
        },
        renderMarkdown(text) {
            if (!text) return '';
            const cleanText = this.stripAnsi(text);
            return typeof marked !== 'undefined' ? marked.parse(cleanText) : cleanText;
        },
        stripAnsi(text) {
            // Elimina secuencias de escape ANSI comunes y artefactos como 0[
            return text.replace(/[\u001b\u009b][[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/g, '')
                       .replace(/0\[m/g, '') 
                       .replace(/0\[/g, '');
        },
        scrollToBottom(id) {
            this.$nextTick(() => {
                const el = document.getElementById('chat-box-' + id);
                if (el) el.scrollTop = el.scrollHeight;
                const elFocus = document.getElementById('chat-box-focus-' + id);
                if (elFocus) elFocus.scrollTop = elFocus.scrollHeight;
            });
        },
        async initPWA() {
            if ('serviceWorker' in navigator) {
                try {
                    const reg = await navigator.serviceWorker.getRegistration();
                    this.swRegistration = reg;
                    if (reg) {
                        const sub = await reg.pushManager.getSubscription();
                        this.notificationsActive = !!sub;
                    }
                } catch (e) { this.log("Error init SW: " + e.message); }
            }
        },
        async toggleNotifications() {
            if (!VAPID_PUBLIC) { alert("VAPID no configurado en pos.cfg"); return; }
            if (this.notificationsActive) {
                const sub = await this.swRegistration.pushManager.getSubscription();
                if (sub) await sub.unsubscribe();
                this.notificationsActive = false;
                this.log("Notificaciones desactivadas.");
            } else {
                try {
                    const permission = await Notification.requestPermission();
                    if (permission !== 'granted') return;
                    
                    const sub = await this.swRegistration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: this.urlBase64ToUint8Array(VAPID_PUBLIC)
                    });
                    
                    await fetch('push_api.php', {
                        method: 'POST',
                        body: JSON.stringify({ action: 'subscribe', subscription: sub, tipo: 'admin' })
                    });
                    
                    this.notificationsActive = true;
                    this.log("Suscrito a Push VAPID!");
                } catch (e) { alert("Error suscribiendo: " + e.message); }
            }
        },
        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
            return outputArray;
        },
        attachFile(id) {
            this.activeSessionId = id;
            document.getElementById('fileInput').click();
        },
        async handleFileUpload(e) {
            const file = e.target.files[0];
            if (!file || !this.activeSessionId) return;
            const session = this.sessions.find(s => s.id === this.activeSessionId);
            const formData = new FormData();
            formData.append('file', file);
            formData.append('chat_id', this.activeSessionId);
            formData.append('agente', session ? session.agente : 'claude');
            this.log(`Subiendo archivo: ${file.name} a sesión ${this.activeSessionId}`);
            try {
                const res = await fetch('palweb_ai_api.php?action=upload_file', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    this.log("Archivo subido!");
                    this.refreshMessages(this.activeSessionId, true);
                } else {
                    alert("Error subiendo: " + data.msg);
                }
            } catch (e) { this.log("Error de conexión"); }
        },
        startVoice(id) {
            const session = this.sessions.find(s => s.id === id);
            if (!session) return;
            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!Recognition) { alert("Tu navegador no soporta voz."); return; }
            const rec = new Recognition();
            rec.lang = 'es-ES';
            this.recording = true;
            rec.onresult = (e) => {
                const transcript = e.results[0][0].transcript;
                session.input += (session.input ? ' ' : '') + transcript;
            };
            rec.onerror = () => { this.recording = false; };
            rec.onend = () => { this.recording = false; };
            rec.start();
        }
    }
});
</script>

</body>
</html>
