import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from './ui/card';
import { Button } from './ui/button';
import { Badge } from './ui/badge';
import { Alert, AlertDescription } from './ui/alert';
import { 
  AlertTriangle, 
  RotateCcw, 
  Clock, 
  GitCommit, 
  CheckCircle,
  XCircle,
  RefreshCw,
  ExternalLink,
  Activity
} from 'lucide-react';

const DeploymentStatus = () => {
  const [currentDeployment, setCurrentDeployment] = useState(null);
  const [deploymentLogs, setDeploymentLogs] = useState('');
  const [loading, setLoading] = useState(true);
  const [rollbackLoading, setRollbackLoading] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);

  useEffect(() => {
    fetchDeploymentStatus();
  }, []);

  const fetchDeploymentStatus = async () => {
    try {
      setLoading(true);
      
      // Fetch current deployment info
      const historyRes = await fetch('/api/deployment/history?limit=1', {
        credentials: 'include'
      });
      
      if (historyRes.ok) {
        const historyData = await historyRes.json();
        if (historyData.deployments && historyData.deployments.length > 0) {
          setCurrentDeployment(historyData.deployments[0]);
        }
      }

      // Fetch deployment logs
      const logsRes = await fetch('/api/deployment/status', {
        credentials: 'include'
      });
      
      if (logsRes.ok) {
        const logsText = await logsRes.text();
        // Get last 10 lines of logs
        const lines = logsText.split('\n').slice(-10).join('\n');
        setDeploymentLogs(lines);
      }
      
    } catch (err) {
      setError('Failed to fetch deployment status: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleQuickRollback = async () => {
    if (!window.confirm('Are you sure you want to rollback to the previous deployment?')) {
      return;
    }

    try {
      setRollbackLoading(true);
      setError(null);
      setSuccess(null);

      const response = await fetch('/api/deployment/rollback', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
          reason: 'Quick rollback from admin dashboard'
        })
      });

      const data = await response.json();

      if (response.ok) {
        setSuccess('Rollback initiated successfully! Check logs for progress.');
        setTimeout(fetchDeploymentStatus, 2000); // Refresh after 2 seconds
      } else {
        setError(data.message || 'Rollback failed');
      }
    } catch (err) {
      setError('Rollback failed: ' + err.message);
    } finally {
      setRollbackLoading(false);
    }
  };

  const getStatusBadge = (status) => {
    switch (status) {
      case 'success':
        return <Badge className="bg-green-100 text-green-800"><CheckCircle className="w-3 h-3 mr-1" />Active</Badge>;
      case 'failed':
        return <Badge className="bg-red-100 text-red-800"><XCircle className="w-3 h-3 mr-1" />Failed</Badge>;
      case 'rolled_back':
        return <Badge className="bg-orange-100 text-orange-800"><RotateCcw className="w-3 h-3 mr-1" />Rolled Back</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  if (loading) {
    return (
      <Card>
        <CardContent className="flex items-center justify-center p-8">
          <RefreshCw className="w-6 h-6 animate-spin mr-2" />
          Loading deployment status...
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      {error && (
        <Alert className="border-red-200 bg-red-50">
          <AlertTriangle className="h-4 w-4 text-red-600" />
          <AlertDescription className="text-red-800">{error}</AlertDescription>
        </Alert>
      )}

      {success && (
        <Alert className="border-green-200 bg-green-50">
          <CheckCircle className="h-4 w-4 text-green-600" />
          <AlertDescription className="text-green-800">{success}</AlertDescription>
        </Alert>
      )}

      {/* Current Deployment Status */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span className="flex items-center">
              <Activity className="w-5 h-5 mr-2" />
              Current Deployment
            </span>
            <Button onClick={fetchDeploymentStatus} variant="outline" size="sm">
              <RefreshCw className="w-4 h-4 mr-1" />
              Refresh
            </Button>
          </CardTitle>
        </CardHeader>
        <CardContent>
          {currentDeployment ? (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <code className="bg-gray-100 px-2 py-1 rounded text-sm font-mono">
                    {currentDeployment.short_commit}
                  </code>
                  <Badge variant="outline">{currentDeployment.branch}</Badge>
                  {getStatusBadge(currentDeployment.status)}
                </div>
                <div className="text-sm text-gray-500">
                  <Clock className="w-3 h-3 inline mr-1" />
                  {currentDeployment.deployed_at}
                </div>
              </div>
              
              <div className="text-sm text-gray-600">
                <p><strong>Deployed by:</strong> {currentDeployment.deployed_by}</p>
                {currentDeployment.repository && (
                  <p><strong>Repository:</strong> {currentDeployment.repository}</p>
                )}
                {currentDeployment.duration && (
                  <p><strong>Duration:</strong> {currentDeployment.duration}s</p>
                )}
              </div>

              {currentDeployment.rollback_reason && (
                <div className="text-sm text-orange-600 bg-orange-50 p-2 rounded">
                  <strong>Rollback reason:</strong> {currentDeployment.rollback_reason}
                </div>
              )}
            </div>
          ) : (
            <p className="text-gray-500">No deployment information available</p>
          )}
        </CardContent>
      </Card>

      {/* Quick Actions */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center">
            <RotateCcw className="w-5 h-5 mr-2" />
            Quick Actions
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-3">
            <Button 
              onClick={handleQuickRollback}
              disabled={rollbackLoading || !currentDeployment}
              className="bg-orange-600 hover:bg-orange-700"
            >
              {rollbackLoading ? (
                <RefreshCw className="w-4 h-4 mr-2 animate-spin" />
              ) : (
                <RotateCcw className="w-4 h-4 mr-2" />
              )}
              Quick Rollback
            </Button>
            
            <Button 
              onClick={() => window.open('/api/deployment/status', '_blank')}
              variant="outline"
            >
              <ExternalLink className="w-4 h-4 mr-2" />
              View Full Logs
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Recent Logs Preview */}
      {deploymentLogs && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center">
              <GitCommit className="w-5 h-5 mr-2" />
              Recent Deployment Logs
            </CardTitle>
          </CardHeader>
          <CardContent>
            <pre className="bg-gray-900 text-green-400 p-3 rounded text-xs overflow-x-auto max-h-40 overflow-y-auto">
              {deploymentLogs || 'No recent logs available'}
            </pre>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default DeploymentStatus; 