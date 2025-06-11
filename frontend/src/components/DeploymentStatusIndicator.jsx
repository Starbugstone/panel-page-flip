import React, { useState, useEffect } from 'react';
import { Badge } from './ui/badge';
import { 
  CheckCircle,
  XCircle,
  RotateCcw,
  AlertTriangle,
  Activity
} from 'lucide-react';

const DeploymentStatusIndicator = () => {
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchStatus();
    // Refresh status every 30 seconds
    const interval = setInterval(fetchStatus, 30000);
    return () => clearInterval(interval);
  }, []);

  const fetchStatus = async () => {
    try {
      const response = await fetch('/api/deployment/history?limit=1', {
        credentials: 'include'
      });
      
      if (response.ok) {
        const data = await response.json();
        if (data.deployments && data.deployments.length > 0) {
          setStatus(data.deployments[0]);
        }
      }
    } catch (err) {
      // Silently fail - this is just an indicator
    } finally {
      setLoading(false);
    }
  };

  if (loading || !status) {
    return null; // Don't show anything while loading or if no data
  }

  const getStatusDisplay = () => {
    switch (status.status) {
      case 'success':
        return {
          icon: <CheckCircle className="w-3 h-3" />,
          text: 'Deployed',
          className: 'bg-green-100 text-green-800 hover:bg-green-200'
        };
      case 'failed':
        return {
          icon: <XCircle className="w-3 h-3" />,
          text: 'Failed',
          className: 'bg-red-100 text-red-800 hover:bg-red-200'
        };
      case 'rolled_back':
        return {
          icon: <RotateCcw className="w-3 h-3" />,
          text: 'Rolled Back',
          className: 'bg-orange-100 text-orange-800 hover:bg-orange-200'
        };
      default:
        return {
          icon: <Activity className="w-3 h-3" />,
          text: status.status,
          className: 'bg-gray-100 text-gray-800 hover:bg-gray-200'
        };
    }
  };

  const statusDisplay = getStatusDisplay();

  return (
    <Badge 
      className={`${statusDisplay.className} cursor-pointer transition-colors text-xs flex items-center gap-1`}
      title={`Deployment: ${status.short_commit} (${status.deployed_at})`}
      onClick={() => window.location.href = '/admin?tab=deployment'}
    >
      {statusDisplay.icon}
      <span className="hidden sm:inline">{statusDisplay.text}</span>
    </Badge>
  );
};

export default DeploymentStatusIndicator; 