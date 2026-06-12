import { Outlet } from 'react-router-dom';

export default function DashboardPage() {
  return (
    <div style={{ display: 'flex', minHeight: '100vh', fontFamily: 'sans-serif' }}>
      <aside style={{ width: '250px', background: '#1e293b', color: 'white', padding: '1.5rem' }}>
        <h2>Dashboard</h2>
      </aside>
      <main style={{ flex: 1, padding: '2rem', background: '#f8fafc' }}>
        <Outlet />
      </main>
    </div>
  );
}
