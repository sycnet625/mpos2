<style>
body{background:#f6f8fc}
.card{border:0;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.stat{font-size:1.6rem;font-weight:700}
.metric-card{border:1px solid transparent !important;border-radius:14px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
#toastArea{
  position:fixed;
  left:0;
  right:0;
  bottom:14px;
  z-index:2000;
  pointer-events:none;
  padding:0 16px 16px;
}
#alertBox{
  max-width:1280px;
  margin:0 auto;
  pointer-events:auto;
}
#alertBox .toast-msg.slide-in{
  animation: pb-toast-in 220ms ease-out forwards;
}
#alertBox .toast-msg.hide{
  animation: pb-toast-out 220ms ease-in forwards;
}
#alertBox .toast-msg{
  min-height:74px;
  width:100%;
  font-size:1.2rem;
  font-weight:600;
  border:0;
  border-radius:14px;
  padding:1.15rem 1.25rem;
  box-shadow:0 16px 34px rgba(0,0,0,.3);
  backdrop-filter:blur(2px);
  position:relative;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
#alertBox .toast-msg .toast-msg-text{flex:1;}
#alertBox .toast-msg .toast-close{opacity:.9;margin-left:auto;border-radius:999px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;border:1px solid rgba(255,255,255,.45);background:rgba(0,0,0,.12);line-height:1;pointer-events:auto;}
#alertBox .toast-msg.alert-danger{background:linear-gradient(120deg,#991b1b,#dc2626);color:#fff;border-left:10px solid #fca5a5;}
#alertBox .toast-msg.alert-success{background:linear-gradient(120deg,#166534,#16a34a);color:#fff;}
#alertBox .toast-msg.alert-info{background:#0ea5e9;color:#fff;}
#alertBox .toast-msg.alert-warning{
  background:linear-gradient(120deg,#92400e,#b45309);
  color:#fff;
}
@keyframes pb-toast-in{
  from{transform:translateY(24px);opacity:0;}
  to{transform:translateY(0);opacity:1;}
}
@keyframes pb-toast-out{
  from{transform:translateY(0);opacity:1;}
  to{transform:translateY(24px);opacity:0;}
}
#alertBox .is-invalid{
  border-color:#dc2626!important;
  box-shadow:0 0 0 .2rem rgba(220,38,38,.15)!important;
}
.error-block{
  border:2px solid #ef4444!important;
  box-shadow:0 0 0 .2rem rgba(239,68,68,.18)!important;
}
</style>
