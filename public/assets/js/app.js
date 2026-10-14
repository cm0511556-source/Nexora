document.addEventListener('DOMContentLoaded', () => {

    const statUsers =
        document.getElementById('stat-users');

    const statProjects =
        document.getElementById('stat-projects');

    const statUptime =
        document.getElementById('stat-uptime');

    const statTime =
        document.getElementById('stat-time');

    const btnRefresh =
        document.getElementById('btn-refresh-stats');

    const btnExplore =
        document.getElementById('btn-explore');

    const subscribeForm =
        document.getElementById('subscribe-form');

    const emailInput =
        document.getElementById('email-input');

    async function fetchStats() {

        try {

            btnRefresh.innerText =
                'Syncing...';

            const response =
                await fetch('/api/stats');

            if (!response.ok) {
                throw new Error(
                    'Network response was not ok'
                );
            }

            const result =
                await response.json();

            const data = result.data;

            statUsers.innerText =
                Number(
                    data.activeUsers
                ).toLocaleString();

            statProjects.innerText =
                Number(
                    data.projectsCompleted
                ).toLocaleString();

            statUptime.innerText =
                data.uptime;

            statTime.innerText =
                data.serverTime;

            showToast(
                'Analytics synchronized with server!'
            );

        } catch (error) {

            console.error(
                'Error fetching stats:',
                error
            );

            showToast(
                'Failed to sync live data.',
                'error'
            );

        } finally {

            btnRefresh.innerText =
                'Sync Live Stats';
        }
    }

    function showToast(
        message,
        type = 'success'
    ) {

        const container =
            document.getElementById(
                'toast-container'
            );

        const toast =
            document.createElement('div');

        toast.className =
            `toast ${type}`;

        toast.innerText =
            message;

        container.appendChild(toast);

        setTimeout(() => {

            toast.style.opacity = '0';

            toast.style.transform =
                'translateX(100%)';

            toast.style.transition =
                'all 0.3s ease';

            setTimeout(
                () => toast.remove(),
                300
            );

        }, 3000);
    }

    subscribeForm.addEventListener(
        'submit',
        async (e) => {

            e.preventDefault();

            const email =
                emailInput.value.trim();

            try {

                const response =
                    await fetch(
                        '/api/subscribe',
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json'
                            },

                            body: JSON.stringify({
                                email: email
                            })
                        }
                    );

                const result =
                    await response.json();

                if (response.ok) {

                    showToast(
                        result.message,
                        'success'
                    );

                    emailInput.value = '';

                } else {

                    showToast(
                        result.message ||
                        'Subscription failed',
                        'error'
                    );
                }

            } catch (error) {

                console.error(error);

                showToast(
                    'Unable to connect to server.',
                    'error'
                );
            }
        }
    );

    btnRefresh.addEventListener(
        'click',
        fetchStats
    );

    btnExplore.addEventListener(
        'click',
        () => {

            document
                .getElementById('features')
                .scrollIntoView({
                    behavior: 'smooth'
                });
        }
    );

    fetchStats();

});
