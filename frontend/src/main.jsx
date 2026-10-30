import { createRoot } from 'react-dom/client'
import App from './App.jsx'
import './index.css'
import { installStaleChunkRecovery } from './lib/stale-chunk-recovery.js'

installStaleChunkRecovery();

createRoot(document.getElementById("root")).render(<App />);
